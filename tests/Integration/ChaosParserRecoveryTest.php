<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Exceptions\ProtocolException;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Response;
use PHPUnit\Framework\TestCase;
use Tests\Chaos\Harness\FakeAmiServer;
use Tests\Support\RuntimeEnvironment;

require_once __DIR__ . '/../Chaos/Harness/FakeAmiServer.php';
require_once __DIR__ . '/../Support/RuntimeEnvironment.php';

final class ChaosParserRecoveryTest extends TestCase
{
    public function test_s6_truncated_frame_scenario_stays_bounded_and_recovers_message_flow(): void
    {
        $scenario = $this->loadScenario('s6-truncated-frame-recovery.json');
        $script = $scenario['servers'][0]['script'];
        $script[0]['type'] = 'send_truncated_frame';

        $result = $this->runScriptedParse(
            $script,
            (int) $scenario['client_settings']['parser_buffer_cap'],
            (int) $scenario['client_settings']['max_frame_size']
        );

        $this->assertNotEmpty($result['responses']);
        $this->assertLessThanOrEqual(
            (int) $scenario['client_settings']['parser_buffer_cap'],
            $result['diagnostics']['peak_buffer_len']
        );
    }

    public function test_s7_garbage_desync_scenario_keeps_clean_subsequent_frames(): void
    {
        $scenario = $this->loadScenario('s7-garbage-desync-recovery.json');

        $result = $this->runScriptedParse(
            $scenario['servers'][0]['script'],
            (int) $scenario['client_settings']['parser_buffer_cap'],
            (int) $scenario['client_settings']['max_frame_size']
        );

        $actionIds = array_values(array_filter(array_map(
            static fn (Response $response): ?string => $response->getActionId(),
            $result['responses']
        )));

        $this->assertContains('1', $actionIds);
        $this->assertContains('2', $actionIds);
    }

    public function test_s8_oversized_frame_scenario_raises_protocol_exception_with_bounded_buffer(): void
    {
        $scenario = $this->loadScenario('s8-oversized-frame.json');
        $script = $scenario['servers'][0]['script'];
        $script[0]['frame'] = str_replace('<200KB>', str_repeat('X', 200 * 1024), (string) $script[0]['frame']);

        $result = $this->runScriptedParse(
            $script,
            (int) $scenario['client_settings']['parser_buffer_cap'],
            (int) $scenario['client_settings']['max_frame_size']
        );

        $this->assertInstanceOf(ProtocolException::class, $result['exception']);
        $this->assertLessThanOrEqual(
            (int) $scenario['client_settings']['parser_buffer_cap'],
            $result['diagnostics']['peak_buffer_len']
        );
    }

    /**
     * @param list<array<string, mixed>> $script
     * @return array{responses:list<Response>, diagnostics:array<string, int>, exception:\Throwable|null}
     */
    private function runScriptedParse(array $script, int $bufferCap, int $maxFrameSize): array
    {
        $server = new FakeAmiServer($script);
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $classification = RuntimeEnvironment::classifyExecutionOutput($e->getMessage());
            if ($classification === 'SANDBOX_ENVIRONMENT') {
                $this->markTestSkipped('SANDBOX_ENVIRONMENT: fake AMI server bind unavailable.');
            }

            throw $e;
        }

        $socket = stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $server->getPort()),
            $errno,
            $errstr,
            1,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            $classification = RuntimeEnvironment::classifyExecutionOutput("{$errstr} ({$errno})");
            if ($classification === 'SANDBOX_ENVIRONMENT') {
                $server->stop();
                $this->markTestSkipped('SANDBOX_ENVIRONMENT: fake AMI server client connect unavailable.');
            }
        }
        $this->assertIsResource($socket, "Failed to connect to fake AMI server: {$errstr} ({$errno})");
        stream_set_blocking($socket, false);

        $parser = new Parser(bufferCap: $bufferCap, maxFrameSize: $maxFrameSize);
        $exception = null;
        $responses = [];

        $deadline = microtime(true) + 1.5;
        while (microtime(true) < $deadline) {
            $server->tick();
            $chunk = fread($socket, 65536);
            if (is_string($chunk) && $chunk !== '') {
                try {
                    $parser->push($chunk);
                    while (($message = $parser->next()) !== null) {
                        if ($message instanceof Response) {
                            $responses[] = $message;
                        }
                    }
                } catch (\Throwable $e) {
                    $exception = $e;
                    break;
                }
            }
            usleep(1000);
        }

        fclose($socket);
        $server->stop();

        return [
            'responses' => $responses,
            'diagnostics' => $parser->diagnostics(),
            'exception' => $exception,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadScenario(string $filename): array
    {
        $path = __DIR__ . '/../../docs/ami-client/chaos/scenarios/' . $filename;
        $raw = file_get_contents($path);
        $this->assertIsString($raw, "Unable to read scenario file {$path}");
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Invalid JSON in scenario file {$path}");

        return $decoded;
    }
}
