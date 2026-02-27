<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

final class TickSummary
{
    public function __construct(
        public int $bytesRead = 0,
        public int $bytesWritten = 0,
        public int $framesParsed = 0,
        public int $eventsDispatched = 0,
        public int $stateTransitions = 0,
        public int $connectAttempts = 0
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function hasProgress(): bool
    {
        return $this->bytesRead > 0
            || $this->bytesWritten > 0
            || $this->framesParsed > 0
            || $this->eventsDispatched > 0
            || $this->stateTransitions > 0
            || $this->connectAttempts > 0;
    }

    public function merge(self $summary): void
    {
        $this->bytesRead += $summary->bytesRead;
        $this->bytesWritten += $summary->bytesWritten;
        $this->framesParsed += $summary->framesParsed;
        $this->eventsDispatched += $summary->eventsDispatched;
        $this->stateTransitions += $summary->stateTransitions;
        $this->connectAttempts += $summary->connectAttempts;
    }
}
