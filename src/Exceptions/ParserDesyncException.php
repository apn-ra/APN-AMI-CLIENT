<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

/**
 * Thrown when the parser loses sync with the AMI stream.
 */
class ParserDesyncException extends ProtocolException
{
}
