<?php

declare(strict_types=1);

namespace Apn\AmiClient\Tests\Unit\Core;

use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Core\SecretRedactor;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testLogsInJsonFormatWithMandatoryFields(): void
    {
        $logger = new Logger();
        
        ob_start();
        $logger->log('info', 'test message', ['server_key' => 'srv1', 'action_id' => '123']);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('INFO', $decoded['level']);
        $this->assertEquals('test message', $decoded['message']);
        $this->assertEquals('srv1', $decoded['server_key']);
        $this->assertEquals('123', $decoded['action_id']);
        $this->assertNull($decoded['queue_depth']);
        $this->assertArrayHasKey('worker_pid', $decoded);
        $this->assertArrayHasKey('timestamp_ms', $decoded);
    }

    public function testWithServerKeySetsDefault(): void
    {
        $logger = (new Logger())->withServerKey('srv_default');
        
        ob_start();
        $logger->info('test message');
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('srv_default', $decoded['server_key']);
    }

    public function testRedactionInLogger(): void
    {
        $logger = new Logger(new SecretRedactor());
        
        ob_start();
        $logger->info('test message', ['secret' => 'password123']);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('********', $decoded['secret']);
    }

    public function testMandatoryFieldsArePresentEvenIfNotProvided(): void
    {
        $logger = new Logger();
        
        ob_start();
        $logger->info('test message');
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals('unknown', $decoded['server_key']);
        $this->assertNull($decoded['action_id']);
        $this->assertNull($decoded['queue_depth']);
    }

    public function testQueueDepthIsPreservedWhenProvided(): void
    {
        $logger = new Logger();

        ob_start();
        $logger->warning('queue-related', ['queue_depth' => 42]);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertSame(42, $decoded['queue_depth']);
    }

    public function testFallbackOutputWhenJsonEncodingFails(): void
    {
        $logger = new Logger();
        $invalidUtf8 = "bad \xC3\x28";

        ob_start();
        $logger->info('fallback-test', ['server_key' => 'srv1', 'bad' => $invalidUtf8]);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('LOG_FALLBACK', $output);
        $this->assertStringContainsString('level=INFO', $output);
        $this->assertStringContainsString('server_key=srv1', $output);
        $this->assertStringContainsString('message=fallback-test', $output);
    }
}
