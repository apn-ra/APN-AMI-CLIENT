<?php

declare(strict_types=1);

namespace tests\Unit\Protocol;

use Apn\AmiClient\Protocol\Originate;
use Apn\AmiClient\Protocol\Strategies\MultiResponseStrategy;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;
use PHPUnit\Framework\TestCase;

class ActionStrategyOverrideTest extends TestCase
{
    public function test_originate_supports_strategy_override(): void
    {
        $originate = new Originate('PJSIP/100');
        $this->assertInstanceOf(SingleResponseStrategy::class, $originate->getCompletionStrategy());

        $customStrategy = new MultiResponseStrategy('OriginateResponse');
        $overridden = new Originate('PJSIP/100', strategy: $customStrategy);
        $this->assertSame($customStrategy, $overridden->getCompletionStrategy());
    }

    public function test_with_action_id_preserves_overridden_strategy(): void
    {
        $customStrategy = new MultiResponseStrategy('OriginateResponse');
        $originate = new Originate('PJSIP/100', strategy: $customStrategy);
        $newOriginate = $originate->withActionId('server:1:1');

        $this->assertSame($customStrategy, $newOriginate->getCompletionStrategy());
    }
}
