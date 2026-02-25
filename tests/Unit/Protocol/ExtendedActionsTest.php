<?php

declare(strict_types=1);

namespace tests\Unit\Protocol;

use Apn\AmiClient\Protocol\QueueStatus;
use Apn\AmiClient\Protocol\QueueSummary;
use Apn\AmiClient\Protocol\PJSIPShowEndpoints;
use Apn\AmiClient\Protocol\PJSIPShowEndpoint;
use Apn\AmiClient\Protocol\Strategies\MultiResponseStrategy;
use PHPUnit\Framework\TestCase;

class ExtendedActionsTest extends TestCase
{
    public function test_queue_status_action(): void
    {
        $action = new QueueStatus(queue: 'support', member: 'PJSIP/101', actionId: 'server:1:1');

        $this->assertEquals('QueueStatus', $action->getActionName());
        $this->assertEquals('server:1:1', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('support', $params['Queue']);
        $this->assertEquals('PJSIP/101', $params['Member']);

        $strategy = $action->getCompletionStrategy();
        $this->assertInstanceOf(MultiResponseStrategy::class, $strategy);
        
        // Check if strategy name is correct (using reflection as property is private)
        $reflection = new \ReflectionClass($strategy);
        $property = $reflection->getProperty('completeEventName');
        $this->assertEquals('QueueStatusComplete', $property->getValue($strategy));

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
        $this->assertEquals('support', $newAction->getParameters()['Queue']);
    }

    public function test_queue_summary_action(): void
    {
        $action = new QueueSummary(queue: 'support', actionId: 'server:1:2');

        $this->assertEquals('QueueSummary', $action->getActionName());
        $this->assertEquals('server:1:2', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('support', $params['Queue']);

        $strategy = $action->getCompletionStrategy();
        $this->assertInstanceOf(MultiResponseStrategy::class, $strategy);
        
        $reflection = new \ReflectionClass($strategy);
        $property = $reflection->getProperty('completeEventName');
        $this->assertEquals('QueueSummaryComplete', $property->getValue($strategy));

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }

    public function test_pjsip_show_endpoints_action(): void
    {
        $action = new PJSIPShowEndpoints(actionId: 'server:1:3');

        $this->assertEquals('PJSIPShowEndpoints', $action->getActionName());
        $this->assertEquals('server:1:3', $action->getActionId());
        
        $this->assertEmpty($action->getParameters());

        $strategy = $action->getCompletionStrategy();
        $this->assertInstanceOf(MultiResponseStrategy::class, $strategy);
        
        $reflection = new \ReflectionClass($strategy);
        $property = $reflection->getProperty('completeEventName');
        $this->assertEquals('EndpointListComplete', $property->getValue($strategy));

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }

    public function test_pjsip_show_endpoint_action(): void
    {
        $action = new PJSIPShowEndpoint(endpoint: '100', actionId: 'server:1:4');

        $this->assertEquals('PJSIPShowEndpoint', $action->getActionName());
        $this->assertEquals('server:1:4', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('100', $params['Endpoint']);

        $strategy = $action->getCompletionStrategy();
        $this->assertInstanceOf(MultiResponseStrategy::class, $strategy);
        
        $reflection = new \ReflectionClass($strategy);
        $property = $reflection->getProperty('completeEventName');
        $this->assertEquals('EndpointDetailComplete', $property->getValue($strategy));

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
        $this->assertEquals('100', $newAction->getParameters()['Endpoint']);
    }
}
