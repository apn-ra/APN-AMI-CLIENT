<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

/**
 * Represents a Response from the AMI server.
 */
readonly class Response extends Message
{
    /**
     * Whether the response indicates success.
     */
    public function isSuccess(): bool
    {
        $response = $this->getHeader('Response');
        return is_string($response) && strcasecmp($response, 'success') === 0;
    }

    /**
     * Get the ActionID if present.
     */
    public function getActionId(): ?string
    {
        $id = $this->getHeader('ActionID');
        if (is_array($id)) {
            return (string)($id[0] ?? null);
        }
        return is_string($id) ? $id : null;
    }

    /**
     * Get the message header if present.
     */
    public function getMessageHeader(): ?string
    {
        $msg = $this->getHeader('Message');
        return is_string($msg) ? $msg : null;
    }
}
