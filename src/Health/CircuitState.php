<?php

declare(strict_types=1);

namespace Apn\AmiClient\Health;

/**
 * Circuit breaker states.
 */
enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}
