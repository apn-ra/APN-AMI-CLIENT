<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol\Strategies;

use Apn\AmiClient\Core\Contracts\EventOnlyCompletionStrategyInterface;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Response;

/**
 * Strategy for async actions where completion is determined by terminal events.
 */
final class AsyncEventStrategy implements EventOnlyCompletionStrategyInterface
{
    private bool $complete = false;
    /** @var array<string, true> */
    private array $terminalEvents = [];
    /** @var string[] */
    private array $terminalEventNames = [];
    private readonly ?\Closure $terminalPredicate;

    /**
     * @param string[] $terminalEventNames
     * @param callable(Event): bool|null $terminalPredicate
     */
    public function __construct(
        array $terminalEventNames = ['OriginateResponse'],
        ?callable $terminalPredicate = null,
        private readonly int $maxDurationMs = 30000,
        private readonly int $maxMessages = 256,
    ) {
        foreach ($terminalEventNames as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed === '') {
                continue;
            }

            $normalized = strtolower($trimmed);
            if (isset($this->terminalEvents[$normalized])) {
                continue;
            }

            $this->terminalEvents[$normalized] = true;
            $this->terminalEventNames[] = $trimmed;
        }

        $this->terminalPredicate = $terminalPredicate !== null
            ? \Closure::fromCallable($terminalPredicate)
            : null;
    }

    public function onResponse(Response $response): bool
    {
        if (!$response->isSuccess()) {
            $this->complete = true;
            return true;
        }

        return false;
    }

    public function onEvent(Event $event): bool
    {
        $eventName = strtolower($event->getName());
        if (isset($this->terminalEvents[$eventName])) {
            $this->complete = true;
            return true;
        }

        if ($this->terminalPredicate !== null && ($this->terminalPredicate)($event) === true) {
            $this->complete = true;
            return true;
        }

        return false;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function getMaxDurationMs(): int
    {
        return $this->maxDurationMs;
    }

    public function getMaxMessages(): int
    {
        return $this->maxMessages;
    }

    public function getTerminalEventNames(): array
    {
        return $this->terminalEventNames;
    }
}
