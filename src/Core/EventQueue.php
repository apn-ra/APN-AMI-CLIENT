<?php

declare(strict_types=1);

namespace Apn\AmiClient\Core;

use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Events\AmiEvent;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use SplQueue;

/**
 * Fixed-capacity event queue with drop-oldest policy.
 */
class EventQueue
{
    private SplQueue $queue;
    private int $capacity;
    private int $droppedEvents = 0;
    private MetricsCollectorInterface $metrics;
    /** @var array<string, string> */
    private array $labels;

    public function __construct(
        int $capacity = 10000,
        ?MetricsCollectorInterface $metrics = null,
        array $labels = []
    ) {
        if ($capacity <= 0) {
            throw new InvalidConfigurationException(sprintf(
                'Event queue capacity must be >= 1; got %d',
                $capacity
            ));
        }

        $this->queue = new SplQueue();
        $this->capacity = $capacity;
        $this->metrics = $metrics ?? new NullMetricsCollector();
        $this->labels = $labels;
    }

    /**
     * Pushes an event to the queue.
     * If the queue is full, the oldest event is dropped.
     */
    public function push(AmiEvent $event): void
    {
        if ($this->queue->count() >= $this->capacity) {
            $this->queue->dequeue(); // Discard oldest
            $this->droppedEvents++;
            
            $this->metrics->increment('ami_dropped_events_total', $this->labels);
        }
        $this->queue->enqueue($event);
    }

    /**
     * Returns the next event from the queue, or null if empty.
     */
    public function pop(): ?AmiEvent
    {
        if ($this->queue->isEmpty()) {
            return null;
        }
        return $this->queue->dequeue();
    }

    /**
     * Returns the number of dropped events.
     */
    public function getDroppedEventsCount(): int
    {
        return $this->droppedEvents;
    }

    /**
     * Returns the current queue size.
     */
    public function count(): int
    {
        return $this->queue->count();
    }

    /**
     * Returns the queue capacity.
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }
}
