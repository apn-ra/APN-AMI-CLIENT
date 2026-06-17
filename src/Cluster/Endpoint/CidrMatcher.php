<?php

declare(strict_types=1);

namespace Apn\AmiClient\Cluster\Endpoint;

use Apn\AmiClient\Exceptions\InvalidConfigurationException;

/**
 * Pure, dependency-free CIDR utility for IPv4 and IPv6.
 *
 * All operations are byte-wise over the binary form produced by inet_pton, so
 * no DNS, no sockets, and no external state are involved. Used by the trusted
 * endpoint resolver to validate that a resolved literal IP falls inside an
 * explicitly allowed network and is not a network/broadcast boundary address.
 */
final class CidrMatcher
{
    /**
     * Validates that a CIDR string is well-formed, throwing on misconfiguration.
     */
    public static function assertValid(string $cidr): void
    {
        self::parse($cidr);
    }

    /**
     * Returns true when $ip (a literal IPv4/IPv6 address) is contained in $cidr.
     * Mismatched families (v4 ip vs v6 cidr) return false rather than throwing.
     */
    public static function contains(string $cidr, string $ip): bool
    {
        [$network, $prefix] = self::parse($cidr);

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (strlen($ipBin) !== strlen($network)) {
            return false; // different address families
        }

        return self::sharesPrefix($ipBin, $network, $prefix);
    }

    /**
     * Network (all-zero host bits) address of the CIDR, as a literal string.
     */
    public static function networkAddress(string $cidr): string
    {
        [$network] = self::parse($cidr);

        return self::binToIp($network);
    }

    /**
     * Broadcast / all-ones host-bits address of the CIDR, as a literal string.
     * For IPv6 this is simply the highest address in the range.
     */
    public static function broadcastAddress(string $cidr): string
    {
        [$network, $prefix] = self::parse($cidr);
        $byteLen = strlen($network);
        $totalBits = $byteLen * 8;

        $result = $network;
        for ($bit = $prefix; $bit < $totalBits; $bit++) {
            $byteIndex = intdiv($bit, 8);
            $bitInByte = 7 - ($bit % 8);
            $result[$byteIndex] = chr(ord($result[$byteIndex]) | (1 << $bitInByte));
        }

        return self::binToIp($result);
    }

    /**
     * @return array{0: string, 1: int} [networkBinary, prefixBits]
     */
    private static function parse(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new InvalidConfigurationException(sprintf('Invalid CIDR "%s": missing prefix length.', $cidr));
        }

        [$address, $prefixStr] = explode('/', $cidr, 2);

        if ($prefixStr === '' || !ctype_digit($prefixStr)) {
            throw new InvalidConfigurationException(sprintf('Invalid CIDR "%s": prefix length must be a non-negative integer.', $cidr));
        }

        $bin = @inet_pton($address);
        if ($bin === false) {
            throw new InvalidConfigurationException(sprintf('Invalid CIDR "%s": "%s" is not a valid IP address.', $cidr, $address));
        }

        $prefix = (int) $prefixStr;
        $maxBits = strlen($bin) * 8;
        if ($prefix > $maxBits) {
            throw new InvalidConfigurationException(sprintf('Invalid CIDR "%s": prefix length exceeds %d bits.', $cidr, $maxBits));
        }

        return [self::applyMask($bin, $prefix), $prefix];
    }

    private static function applyMask(string $bin, int $prefix): string
    {
        $byteLen = strlen($bin);
        $masked = $bin;
        for ($bit = 0; $bit < $byteLen * 8; $bit++) {
            if ($bit < $prefix) {
                continue;
            }
            $byteIndex = intdiv($bit, 8);
            $bitInByte = 7 - ($bit % 8);
            $masked[$byteIndex] = chr(ord($masked[$byteIndex]) & ~(1 << $bitInByte));
        }

        return $masked;
    }

    private static function sharesPrefix(string $a, string $b, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }

    private static function binToIp(string $bin): string
    {
        $ip = @inet_ntop($bin);
        if ($ip === false) {
            throw new InvalidConfigurationException('Failed to convert binary address back to literal IP.');
        }

        return $ip;
    }
}
