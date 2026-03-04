<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\ActionErrorResponseException;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Originate;
use Apn\AmiClient\Protocol\Response;
use PHPUnit\Framework\TestCase;

final class AsyncOriginateCompletionTest extends TestCase
{
    public function test_async_originate_completes_on_originate_response_event(): void
    {
        $registry = new CorrelationRegistry();
        $action = (new Originate(channel: 'PJSIP/100', async: true))->withActionId('node-a:inst:1');
        $pending = $registry->register($action);

        $resolved = false;
        $pending->onComplete(function ($e, $r, $events) use (&$resolved): void {
            $resolved = $e === null
                && $r !== null
                && $r->isSuccess()
                && count($events) === 1
                && $events[0]->getName() === 'OriginateResponse';
        });

        $registry->handleEvent(new Event([
            'event' => 'OriginateResponse',
            'actionid' => 'node-a:inst:1',
            'response' => 'Success',
        ]));

        $this->assertTrue($resolved);
        $this->assertSame(0, $registry->count());
    }

    public function test_async_originate_error_response_fails_without_orphaned_pending_action(): void
    {
        $registry = new CorrelationRegistry();
        $action = (new Originate(channel: 'PJSIP/100', async: true))->withActionId('node-a:inst:2');
        $pending = $registry->register($action);

        $captured = null;
        $pending->onComplete(function ($e) use (&$captured): void {
            $captured = $e;
        });

        $registry->handleResponse(new Response([
            'response' => 'Error',
            'actionid' => 'node-a:inst:2',
            'message' => 'Originate denied',
        ]));

        $this->assertInstanceOf(ActionErrorResponseException::class, $captured);
        $this->assertSame(0, $registry->count());
    }
}
