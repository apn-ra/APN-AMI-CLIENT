<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\ClientOptions;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    public function testFromArrayMapsRedactionOptions(): void
    {
        $options = ClientOptions::fromArray([
            'redaction_keys' => ['custom_secret'],
            'redaction_key_patterns' => ['/^x-.+$/i'],
            'max_action_id_length' => 96,
            'enforce_ip_endpoints' => true,
        ]);

        $this->assertSame(['custom_secret'], $options->redactionKeys);
        $this->assertSame(['/^x-.+$/i'], $options->redactionKeyPatterns);
        $this->assertSame(96, $options->maxActionIdLength);
        $this->assertTrue($options->enforceIpEndpoints);
    }

    public function testInvalidRedactionPatternThrows(): void
    {
        $options = ClientOptions::fromArray([
            'redaction_key_patterns' => ['/(unclosed/'],
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $options->createRedactor();
    }
}
