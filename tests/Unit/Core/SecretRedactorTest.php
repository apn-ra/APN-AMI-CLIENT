<?php

declare(strict_types=1);

namespace Apn\AmiClient\Tests\Unit\Core;

use Apn\AmiClient\Core\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new SecretRedactor();
    }

    public function testRedactsSensitiveKeys(): void
    {
        $data = [
            'secret' => 'mysecret',
            'password' => 'mypass',
            'token' => 'mytoken',
            'auth' => 'Bearer value',
            'key' => 'k-value',
            'variable' => 'sensitive=value',
            'safe' => 'safevalue',
        ];

        $expected = [
            'secret' => '********',
            'password' => '********',
            'token' => '********',
            'auth' => '********',
            'key' => '********',
            'variable' => '********',
            'safe' => 'safevalue',
        ];

        $this->assertEquals($expected, $this->redactor->redact($data));
    }

    public function testRedactsNestedKeys(): void
    {
        $data = [
            'nested' => [
                'secret' => 'nestedsecret',
                'other' => 'value',
            ],
            'password' => 'topmypass',
        ];

        $expected = [
            'nested' => [
                'secret' => '********',
                'other' => 'value',
            ],
            'password' => '********',
        ];

        $this->assertEquals($expected, $this->redactor->redact($data));
    }

    public function testRedactsCaseInsensitiveKeys(): void
    {
        $data = [
            'SECRET' => 'mysecret',
            'Password' => 'mypass',
        ];

        $expected = [
            'SECRET' => '********',
            'Password' => '********',
        ];

        $this->assertEquals($expected, $this->redactor->redact($data));
    }

    public function testDefaultRegexPolicyRedactsTokenAuthAndKeyPatterns(): void
    {
        $data = [
            'api_token' => 'token-value',
            'auth_header' => 'Bearer abc',
            'private_key_path' => '/tmp/key.pem',
            'x_auth_ctx' => 'authz',
            'safe' => 'value',
        ];

        $redacted = $this->redactor->redact($data);

        $this->assertSame('********', $redacted['api_token']);
        $this->assertSame('********', $redacted['auth_header']);
        $this->assertSame('********', $redacted['private_key_path']);
        $this->assertSame('********', $redacted['x_auth_ctx']);
        $this->assertSame('value', $redacted['safe']);
    }

    public function testAdditionalPolicyIsAppliedFromConstructor(): void
    {
        $redactor = new SecretRedactor(
            additionalSensitiveKeys: ['custom_secret_field'],
            additionalSensitiveKeyPatterns: ['/^x-.+$/i']
        );

        $redacted = $redactor->redact([
            'custom_secret_field' => '1',
            'x-auth-extra' => '2',
            'normal' => '3',
        ]);

        $this->assertSame('********', $redacted['custom_secret_field']);
        $this->assertSame('********', $redacted['x-auth-extra']);
        $this->assertSame('3', $redacted['normal']);
    }

    public function testValueBasedRedactionMasksEmbeddedSecrets(): void
    {
        $redacted = $this->redactor->redact([
            'note' => 'token=abc123; user=me',
            'header' => 'Authorization: Bearer abc.def.ghi',
            'safe' => 'no secrets here',
        ]);

        $this->assertSame('********; user=me', $redacted['note']);
        $this->assertSame('Authorization: ********', $redacted['header']);
        $this->assertSame('no secrets here', $redacted['safe']);
    }

    public function testValueRedactionAppliesToNestedContext(): void
    {
        $redacted = $this->redactor->redact([
            'context' => [
                'note' => 'password=supersecret; user=me',
                'safe' => 'ok',
            ],
            'list' => [
                'token=abc',
                'no secret',
            ],
        ]);

        $this->assertSame('********; user=me', $redacted['context']['note']);
        $this->assertSame('ok', $redacted['context']['safe']);
        $this->assertSame('********', $redacted['list'][0]);
        $this->assertSame('no secret', $redacted['list'][1]);
    }
}
