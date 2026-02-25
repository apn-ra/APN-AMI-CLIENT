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
            'variable' => 'sensitive=value',
            'safe' => 'safevalue',
        ];

        $expected = [
            'secret' => '********',
            'password' => '********',
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
}
