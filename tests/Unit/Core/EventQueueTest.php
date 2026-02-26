<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Apn\AmiClient\Core\EventQueue;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class EventQueueTest extends TestCase
{
    public function testConstructingWithZeroCapacityThrowsTypedException(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Event queue capacity must be >= 1; got 0');
        new EventQueue(0);
    }

    public function testConstructingWithNegativeCapacityThrowsTypedException(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Event queue capacity must be >= 1; got -5');
        new EventQueue(-5);
    }
}
