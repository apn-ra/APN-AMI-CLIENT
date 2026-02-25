<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

use Apn\AmiClient\Events\AmiEvent;

/**
 * Interface for event filtering.
 */
interface EventFilterInterface
{
    /**
     * Returns true if the event should be kept.
     */
    public function shouldKeep(AmiEvent $event): bool;
}
