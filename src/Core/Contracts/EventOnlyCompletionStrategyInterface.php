<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core\Contracts;

/**
 * Marker for completion strategies that intentionally complete on events
 * without requiring an AMI response frame.
 */
interface EventOnlyCompletionStrategyInterface extends CompletionStrategyInterface
{
}

