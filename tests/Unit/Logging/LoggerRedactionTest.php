<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use Apn\AmiClient\Core\Logger;
use Apn\AmiClient\Core\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class LoggerRedactionTest extends TestCase
{
    public function test_logger_redacts_default_sensitive_key_list_fields(): void
    {
        $logger = new Logger(new SecretRedactor());

        ob_start();
        $logger->warning('redaction', [
            'password' => 'p',
            'token' => 't',
            'auth' => 'a',
            'key' => 'k',
            'safe' => 'ok',
        ]);
        $output = ob_get_clean();

        $decoded = json_decode((string) $output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('********', $decoded['password']);
        $this->assertSame('********', $decoded['token']);
        $this->assertSame('********', $decoded['auth']);
        $this->assertSame('********', $decoded['key']);
        $this->assertSame('ok', $decoded['safe']);
    }

    public function test_logger_redacts_regex_matched_fields(): void
    {
        $logger = new Logger(new SecretRedactor());

        ob_start();
        $logger->warning('redaction', [
            'api_token' => 'token-1',
            'auth_header' => 'Bearer xyz',
            'private_key_path' => '/tmp/key.pem',
            'normal' => 'keep',
        ]);
        $output = ob_get_clean();

        $decoded = json_decode((string) $output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('********', $decoded['api_token']);
        $this->assertSame('********', $decoded['auth_header']);
        $this->assertSame('********', $decoded['private_key_path']);
        $this->assertSame('keep', $decoded['normal']);
    }
}

