<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\ActionErrorResponseException;
use Apn\AmiClient\Protocol\GenericAction;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;
use PHPUnit\Framework\TestCase;

final class CorrelationStormTest extends TestCase
{
    public function test_it_resolves_1k_pending_actions_with_out_of_order_responses_and_zero_leaks(): void
    {
        $registry = new CorrelationRegistry();
        $ids = [];
        $resolved = 0;
        $failed = 0;
        $wrongSuccessOnError = 0;

        for ($i = 1; $i <= 1000; $i++) {
            $id = sprintf('node-a:%04d', $i);
            $ids[] = $id;
            $action = (new GenericAction('Ping', strategy: new SingleResponseStrategy(maxDurationMs: 1500)))
                ->withActionId($id);

            $pending = $registry->register($action);
            $pending->onComplete(function ($e, $response) use (&$resolved, &$failed, &$wrongSuccessOnError): void {
                if ($e === null) {
                    $resolved++;
                    return;
                }

                $failed++;
                if ($e instanceof ActionErrorResponseException && $response !== null && $response->isSuccess()) {
                    $wrongSuccessOnError++;
                }
            });
        }

        $shuffled = $ids;
        shuffle($shuffled);
        foreach ($shuffled as $index => $id) {
            if ($index % 10 === 0) {
                $registry->handleResponse(new Response([
                    'response' => 'Error',
                    'actionid' => $id,
                    'message' => 'Permission denied',
                ]));
                continue;
            }

            $registry->handleResponse(new Response([
                'response' => 'Success',
                'actionid' => $id,
                'message' => 'Pong',
            ]));
        }

        $registry->sweep();

        $this->assertSame(0, $registry->count(), 'Pending actions leaked after out-of-order storm');
        $this->assertSame(900, $resolved);
        $this->assertSame(100, $failed);
        $this->assertSame(0, $wrongSuccessOnError);

        $diag = $registry->diagnostics();
        $this->assertSame(1000, $diag['matched_responses']);
        $this->assertSame(0, $diag['unmatched_responses']);
        $this->assertSame(0, $diag['timeouts']);
        $this->assertSame(900, $diag['completed_actions']);
        $this->assertSame(100, $diag['failed_actions']);
        $this->assertSame(0, $diag['pending']);
    }
}

