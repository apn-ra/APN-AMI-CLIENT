<?php

declare(strict_types=1);

namespace Apn\AmiClient\Protocol;

/**
 * Represents an unsolicited Event from the AMI server.
 */
readonly class Event extends Message
{
    /**
     * Get the name of the event.
     */
    public function getName(): string
    {
        $event = $this->getHeader('Event');
        return is_string($event) ? $event : 'Unknown';
    }
}
