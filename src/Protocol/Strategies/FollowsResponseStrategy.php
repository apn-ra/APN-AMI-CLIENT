<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol\Strategies;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Event;

/**
 * Strategy for actions that return a "Follows" response.
 * Usually treated as a single response that contains all data.
 */
final class FollowsResponseStrategy implements CompletionStrategyInterface
{
    private bool $complete = false;
    private const string SENTINEL = '--END COMMAND--';

    /**
     * @param int $maxOutputSize Hard limit for the follows output (default 1MB).
     */
    public function __construct(
        private readonly int $maxOutputSize = 1048576
    ) {
    }

    public function onResponse(Response $response): bool
    {
        $type = (string)$response->getHeader('Response');
        
        // Success or Error responses complete the action immediately.
        if (strcasecmp($type, 'success') === 0 || strcasecmp($type, 'error') === 0) {
            $this->complete = true;
            return true;
        }

        // For "Follows" or other types, check for the sentinel in any of the header values
        foreach ($response->getHeaders() as $value) {
            $valueStr = is_array($value) ? implode("\n", $value) : (string)$value;
            
            if (strlen($valueStr) > $this->maxOutputSize) {
                // Section 5.1: If limit is exceeded, fail the action with a ProtocolException
                throw new \Apn\AmiClient\Exceptions\ProtocolException(
                    sprintf("Follows response output size exceeds %d bytes limit", $this->maxOutputSize)
                );
            }

            if (str_contains($valueStr, self::SENTINEL)) {
                $this->complete = true;
                return true;
            }
        }

        // Section 5.1 says to buffer until terminator is reached.
        // If not found in this response, return false to wait for more messages.
        return false;
    }

    public function onEvent(Event $event): bool
    {
        return false;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
