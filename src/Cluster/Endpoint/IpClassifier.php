<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

/**
 * Classifies a literal IP address into a coarse security category so the
 * {@see ReservedRangePolicy} can allow or deny it. Pure and offline: operates
 * only on the literal string, never resolves anything.
 *
 * Categories: loopback, link_local, multicast, unspecified, broadcast,
 * private, reserved, public.
 */
final class IpClassifier
{
    public const string LOOPBACK = 'loopback';
    public const string LINK_LOCAL = 'link_local';
    public const string MULTICAST = 'multicast';
    public const string UNSPECIFIED = 'unspecified';
    public const string BROADCAST = 'broadcast';
    public const string PRIVATE = 'private';
    public const string RESERVED = 'reserved';
    public const string PUBLIC = 'public';

    /** Cloud metadata service address (link-local, called out for clarity). */
    public const string METADATA_IPV4 = '169.254.169.254';

    public static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public static function isIpv6(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Returns one of the category constants for a valid literal IP.
     * Returns RESERVED for anything not recognised as a safe category.
     */
    public static function classify(string $ip): string
    {
        if (self::isIpv6($ip)) {
            return self::classifyIpv6($ip);
        }

        return self::classifyIpv4($ip);
    }

    public static function isMetadataAddress(string $ip): bool
    {
        return $ip === self::METADATA_IPV4
            || CidrMatcher::contains('fd00:ec2::254/128', $ip); // AWS IMDS IPv6 best-effort
    }

    private static function classifyIpv4(string $ip): string
    {
        if ($ip === '0.0.0.0') {
            return self::UNSPECIFIED;
        }
        if ($ip === '255.255.255.255') {
            return self::BROADCAST;
        }
        if (CidrMatcher::contains('127.0.0.0/8', $ip)) {
            return self::LOOPBACK;
        }
        if (CidrMatcher::contains('169.254.0.0/16', $ip)) {
            return self::LINK_LOCAL; // includes metadata 169.254.169.254
        }
        if (CidrMatcher::contains('224.0.0.0/4', $ip)) {
            return self::MULTICAST;
        }
        if (
            CidrMatcher::contains('10.0.0.0/8', $ip)
            || CidrMatcher::contains('172.16.0.0/12', $ip)
            || CidrMatcher::contains('192.168.0.0/16', $ip)
        ) {
            return self::PRIVATE;
        }
        if (
            CidrMatcher::contains('100.64.0.0/10', $ip)   // CGNAT
            || CidrMatcher::contains('192.0.0.0/24', $ip) // IETF protocol assignments
            || CidrMatcher::contains('192.0.2.0/24', $ip) // documentation TEST-NET-1
            || CidrMatcher::contains('198.51.100.0/24', $ip) // TEST-NET-2
            || CidrMatcher::contains('203.0.113.0/24', $ip)  // TEST-NET-3
            || CidrMatcher::contains('198.18.0.0/15', $ip)   // benchmarking
            || CidrMatcher::contains('240.0.0.0/4', $ip)     // reserved / future use
        ) {
            return self::RESERVED;
        }

        return self::PUBLIC;
    }

    private static function classifyIpv6(string $ip): string
    {
        if (CidrMatcher::contains('::1/128', $ip)) {
            return self::LOOPBACK;
        }
        if (CidrMatcher::contains('::/128', $ip)) {
            return self::UNSPECIFIED;
        }
        if (CidrMatcher::contains('fe80::/10', $ip)) {
            return self::LINK_LOCAL;
        }
        if (CidrMatcher::contains('ff00::/8', $ip)) {
            return self::MULTICAST;
        }
        if (CidrMatcher::contains('fc00::/7', $ip)) {
            return self::PRIVATE; // unique local addresses
        }
        if (
            CidrMatcher::contains('2001:db8::/32', $ip) // documentation
            || CidrMatcher::contains('::ffff:0:0/96', $ip) // IPv4-mapped (handled as v4 elsewhere)
        ) {
            return self::RESERVED;
        }

        return self::PUBLIC;
    }
}
