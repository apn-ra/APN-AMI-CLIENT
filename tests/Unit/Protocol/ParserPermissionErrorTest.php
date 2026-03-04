<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Apn\AmiClient\Protocol\Banner;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserPermissionErrorTest extends TestCase
{
    #[DataProvider('permissionDelimiterFixtures')]
    public function test_it_parses_permission_error_fixture_for_both_delimiters(string $fixture, string $expectedActionId): void
    {
        $parser = new Parser();
        $parser->push($this->fixture($fixture));

        $message = $parser->next();

        $this->assertInstanceOf(Response::class, $message);
        $this->assertSame('Error', $message->getHeader('response'));
        $this->assertSame($expectedActionId, $message->getHeader('actionid'));
        $this->assertSame('Permission denied', $message->getHeader('message'));
        $this->assertNull($parser->next());
    }

    public function test_it_normalizes_header_keys_with_odd_whitespace_and_case(): void
    {
        $parser = new Parser();
        $parser->push($this->fixture('permission-error-odd-whitespace-case-crlf.raw'));

        $message = $parser->next();

        $this->assertInstanceOf(Response::class, $message);
        $this->assertSame('Error', $message->getHeader('response'));
        $this->assertSame('Permission denied', $message->getHeader('message'));
        $this->assertSame('fixture-odd-001', $message->getHeader('actionid'));
    }

    public function test_it_parses_banner_then_response_in_order(): void
    {
        $parser = new Parser();
        $parser->push($this->fixture('permission-error-banner-then-error-crlf.raw'));

        $banner = $parser->next();
        $response = $parser->next();

        $this->assertInstanceOf(Banner::class, $banner);
        $this->assertSame('Asterisk Call Manager/11.0.0', $banner->getVersionString());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Error', $response->getHeader('response'));
        $this->assertSame('fixture-banner-001', $response->getHeader('actionid'));
        $this->assertNull($parser->next());
    }

    public function test_it_emits_bounded_parser_debug_payload_with_redacted_preview(): void
    {
        $logs = [];
        $parser = new Parser();
        $parser->setDebugHook(function (array $payload) use (&$logs): void {
            $logs[] = $payload;
        });

        $parser->push("Response: Error\r\nActionID: dbg-1\r\nSecret: top-secret\r\nMessage: Permission denied\r\n\r\n");
        $message = $parser->next();

        $this->assertInstanceOf(Response::class, $message);
        $this->assertCount(1, $logs);
        $this->assertSame('crlfcrlf', $logs[0]['delimiter_used']);
        $this->assertSame(strlen("Response: Error\r\nActionID: dbg-1\r\nSecret: top-secret\r\nMessage: Permission denied"), $logs[0]['frame_len']);
        $this->assertStringContainsString('Secret: ********', (string) $logs[0]['preview']);
        $this->assertLessThanOrEqual(160, strlen((string) $logs[0]['preview']));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function permissionDelimiterFixtures(): array
    {
        return [
            'crlf' => ['permission-error-standard-crlf.raw', 'fixture-std-crlf-001'],
            'lf' => ['permission-error-standard-lf.raw', 'fixture-std-lf-001'],
        ];
    }

    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../../../docs/ami-client/fixtures/permission-errors/' . $name;
        self::assertFileExists($path);

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }
}
