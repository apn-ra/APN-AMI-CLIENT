<?php

declare(strict_types=1);

namespace Tests\RealPbx;

final class Redactor
{
    /** @var list<string> */
    private array $sensitiveValues;

    /**
     * @param list<string> $sensitiveValues
     */
    public function __construct(array $sensitiveValues = [])
    {
        $this->sensitiveValues = array_values(array_filter(array_unique($sensitiveValues), static fn (string $v): bool => $v !== ''));
    }

    public function redactString(string $value): string
    {
        $redacted = $value;

        foreach ($this->sensitiveValues as $secret) {
            $redacted = str_replace($secret, '***REDACTED***', $redacted);
        }

        $redacted = preg_replace('/(secret\s*[:=]\s*)([^\s]+)/i', '$1***REDACTED***', $redacted) ?? $redacted;
        $redacted = preg_replace('/(password\s*[:=]\s*)([^\s]+)/i', '$1***REDACTED***', $redacted) ?? $redacted;
        $redacted = preg_replace('/(ami_secret\s*[:=]\s*)([^\s]+)/i', '$1***REDACTED***', $redacted) ?? $redacted;

        return $redacted;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function redactMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->redactMixed($item);
            }
            return $out;
        }

        return $value;
    }
}
