<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster\Endpoint;

use Apn\AmiClient\Cluster\Endpoint\CidrMatcher;
use Apn\AmiClient\Cluster\Endpoint\IpClassifier;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class CidrMatcherTest extends TestCase
{
    public function test_ipv4_membership(): void
    {
        $this->assertTrue(CidrMatcher::contains('172.30.10.0/24', '172.30.10.50'));
        $this->assertFalse(CidrMatcher::contains('172.30.10.0/24', '172.30.11.1'));
        $this->assertTrue(CidrMatcher::contains('10.250.0.0/16', '10.250.5.7'));
        $this->assertFalse(CidrMatcher::contains('10.250.0.0/16', '10.251.0.1'));
    }

    public function test_ipv6_membership(): void
    {
        $this->assertTrue(CidrMatcher::contains('fc00::/7', 'fc00::1'));
        $this->assertFalse(CidrMatcher::contains('fc00::/7', '2001:db8::1'));
    }

    public function test_family_mismatch_is_not_a_match(): void
    {
        $this->assertFalse(CidrMatcher::contains('172.30.10.0/24', 'fc00::1'));
        $this->assertFalse(CidrMatcher::contains('fc00::/7', '172.30.10.5'));
    }

    public function test_network_and_broadcast_addresses(): void
    {
        $this->assertSame('172.30.10.0', CidrMatcher::networkAddress('172.30.10.0/24'));
        $this->assertSame('172.30.10.255', CidrMatcher::broadcastAddress('172.30.10.0/24'));
        $this->assertSame('10.250.0.0', CidrMatcher::networkAddress('10.250.5.7/16'));
        $this->assertSame('10.250.255.255', CidrMatcher::broadcastAddress('10.250.5.7/16'));
    }

    public function test_invalid_cidr_throws(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        CidrMatcher::assertValid('172.30.10.0/40');
    }

    public function test_classifier_categories(): void
    {
        $this->assertSame(IpClassifier::PRIVATE, IpClassifier::classify('172.30.10.5'));
        $this->assertSame(IpClassifier::PRIVATE, IpClassifier::classify('10.250.5.7'));
        $this->assertSame(IpClassifier::LOOPBACK, IpClassifier::classify('127.0.0.1'));
        $this->assertSame(IpClassifier::LINK_LOCAL, IpClassifier::classify('169.254.169.254'));
        $this->assertSame(IpClassifier::MULTICAST, IpClassifier::classify('224.0.0.1'));
        $this->assertSame(IpClassifier::UNSPECIFIED, IpClassifier::classify('0.0.0.0'));
        $this->assertSame(IpClassifier::BROADCAST, IpClassifier::classify('255.255.255.255'));
        $this->assertSame(IpClassifier::PUBLIC, IpClassifier::classify('8.8.8.8'));
        $this->assertTrue(IpClassifier::isMetadataAddress('169.254.169.254'));
    }
}
