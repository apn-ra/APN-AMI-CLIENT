<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/lib/EnvironmentFailureClassifier.php';

use Apn\AmiClient\Scripts\EnvironmentFailureClassifier;

final class RuntimeEnvironment
{
    /**
     * @return array{server: resource, port: int}
     */
    public static function createTcpServerOrSkip(TestCase $testCase, string $host = '127.0.0.1'): array
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);
        if (!is_resource($server)) {
            $signature = sprintf(
                "stream_socket_server(): Unable to connect to tcp://%s:0 (%s)\nCould not start mock server: %s",
                $host,
                $errstr !== '' ? $errstr : 'unknown',
                $errstr !== '' ? $errstr : 'unknown'
            );
            $classification = EnvironmentFailureClassifier::classify($signature);
            if ($classification === 'SANDBOX_ENVIRONMENT') {
                $testCase->markTestSkipped('SANDBOX_ENVIRONMENT: local TCP bind unavailable for socket-backed tests.');
            }

            $testCase->fail("Could not start mock server: {$errstr}");
        }

        $name = stream_socket_get_name($server, false);
        if (!is_string($name)) {
            fclose($server);
            $testCase->fail('Unable to resolve mock server socket name.');
        }

        $port = (int) parse_url($name, PHP_URL_PORT);
        stream_set_blocking($server, false);

        return [
            'server' => $server,
            'port' => $port,
        ];
    }

    public static function classifyExecutionOutput(string $output): string
    {
        return EnvironmentFailureClassifier::classify($output);
    }
}

