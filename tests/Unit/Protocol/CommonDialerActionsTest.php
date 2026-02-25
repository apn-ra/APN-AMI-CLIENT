<?php

declare(strict_types=1);

namespace tests\Unit\Protocol;

use Apn\AmiClient\Protocol\Originate;
use Apn\AmiClient\Protocol\Hangup;
use Apn\AmiClient\Protocol\Redirect;
use Apn\AmiClient\Protocol\SetVar;
use Apn\AmiClient\Protocol\GetVar;
use PHPUnit\Framework\TestCase;

class CommonDialerActionsTest extends TestCase
{
    public function test_originate_action(): void
    {
        $action = new Originate(
            channel: 'PJSIP/100',
            exten: '200',
            context: 'default',
            priority: 1,
            callerId: '123',
            variables: ['VAR1' => 'VAL1'],
            actionId: 'server:1:1'
        );

        $this->assertEquals('Originate', $action->getActionName());
        $this->assertEquals('server:1:1', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('PJSIP/100', $params['Channel']);
        $this->assertEquals('200', $params['Exten']);
        $this->assertEquals('default', $params['Context']);
        $this->assertEquals('1', $params['Priority']);
        $this->assertEquals('123', $params['CallerID']);
        $this->assertContains('VAR1=VAL1', $params['Variable']);
        $this->assertEquals('true', $params['Async']);

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
        $this->assertEquals('PJSIP/100', $newAction->getParameters()['Channel']);
    }

    public function test_hangup_action(): void
    {
        $action = new Hangup('PJSIP/100', 16, actionId: 'server:1:2');

        $this->assertEquals('Hangup', $action->getActionName());
        $this->assertEquals('server:1:2', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('PJSIP/100', $params['Channel']);
        $this->assertEquals('16', $params['Cause']);

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }

    public function test_redirect_action(): void
    {
        $action = new Redirect(
            channel: 'PJSIP/100',
            exten: '200',
            context: 'default',
            priority: 1,
            extraChannel: 'PJSIP/101',
            actionId: 'server:1:3'
        );

        $this->assertEquals('Redirect', $action->getActionName());
        $this->assertEquals('server:1:3', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('PJSIP/100', $params['Channel']);
        $this->assertEquals('200', $params['Exten']);
        $this->assertEquals('default', $params['Context']);
        $this->assertEquals('1', $params['Priority']);
        $this->assertEquals('PJSIP/101', $params['ExtraChannel']);

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }

    public function test_setvar_action(): void
    {
        $action = new SetVar('MYVAR', 'MYVAL', 'PJSIP/100', actionId: 'server:1:4');

        $this->assertEquals('SetVar', $action->getActionName());
        $this->assertEquals('server:1:4', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('MYVAR', $params['Variable']);
        $this->assertEquals('MYVAL', $params['Value']);
        $this->assertEquals('PJSIP/100', $params['Channel']);

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }

    public function test_getvar_action(): void
    {
        $action = new GetVar('MYVAR', 'PJSIP/100', actionId: 'server:1:5');

        $this->assertEquals('GetVar', $action->getActionName());
        $this->assertEquals('server:1:5', $action->getActionId());
        
        $params = $action->getParameters();
        $this->assertEquals('MYVAR', $params['Variable']);
        $this->assertEquals('PJSIP/100', $params['Channel']);

        $newAction = $action->withActionId('new:id');
        $this->assertNotSame($action, $newAction);
        $this->assertEquals('new:id', $newAction->getActionId());
    }
}
