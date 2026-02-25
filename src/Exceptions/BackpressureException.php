<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when internal buffers or registries reach their capacity.
 */
class BackpressureException extends AmiException
{
}
