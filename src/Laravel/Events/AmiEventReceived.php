<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel\Events;

use Apn\AmiClient\Events\AmiEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AmiEventReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AmiEvent $event
    ) {}
}
