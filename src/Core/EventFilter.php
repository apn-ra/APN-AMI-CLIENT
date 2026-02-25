<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Apn\AmiClient\Core\Contracts\EventFilterInterface;
use Apn\AmiClient\Events\AmiEvent;

/**
 * Basic event filter based on event names.
 */
class EventFilter implements EventFilterInterface
{
    /**
     * @param string[] $allowedEvents If empty, all events are allowed unless blacklisted.
     * @param string[] $blockedEvents
     */
    public function __construct(
        private array $allowedEvents = [],
        private array $blockedEvents = []
    ) {
    }

    public function shouldKeep(AmiEvent $event): bool
    {
        $name = strtolower($event->getName());

        if (!empty($this->blockedEvents)) {
            foreach ($this->blockedEvents as $blocked) {
                if (strtolower($blocked) === $name) {
                    return false;
                }
            }
        }

        if (!empty($this->allowedEvents)) {
            foreach ($this->allowedEvents as $allowed) {
                if (strtolower($allowed) === $name) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }
}
