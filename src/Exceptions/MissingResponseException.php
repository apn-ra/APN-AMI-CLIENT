<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when an action reaches completion without an explicit AMI response.
 */
class MissingResponseException extends ProtocolException
{
}

