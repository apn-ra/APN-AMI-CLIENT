<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

final class InvalidConfigurationException extends AmiException
{
    private ?string $patternType = null;
    private ?string $pattern = null;
    private ?string $patternError = null;

    public static function forRedactionPattern(string $patternType, string $pattern, string $error): self
    {
        $exception = new self(sprintf(
            'Invalid redaction %s regex pattern "%s": %s',
            $patternType,
            $pattern,
            $error
        ));

        $exception->patternType = $patternType;
        $exception->pattern = $pattern;
        $exception->patternError = $error;

        return $exception;
    }

    public function getPatternType(): ?string
    {
        return $this->patternType;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function getPatternError(): ?string
    {
        return $this->patternError;
    }
}
