<?php

declare(strict_types=1);

namespace Tests\Unit\Correlation\Strategies;

use Apn\AmiClient\Correlation\Strategies\FollowsResponseStrategy;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Exceptions\ProtocolException;
use PHPUnit\Framework\TestCase;

class FollowsResponseStrategyTest extends TestCase
{
    public function test_it_completes_on_success_or_error(): void
    {
        $strategy = new FollowsResponseStrategy();
        
        $this->assertTrue($strategy->onResponse(new Response(['response' => 'Success'])));
        $this->assertTrue($strategy->isComplete());
        
        $strategy2 = new FollowsResponseStrategy();
        $this->assertTrue($strategy2->onResponse(new Response(['response' => 'Error'])));
        $this->assertTrue($strategy2->isComplete());
    }

    public function test_it_completes_on_sentinel_in_follows_response(): void
    {
        $strategy = new FollowsResponseStrategy();
        
        $response = new Response([
            'response' => 'Follows',
            'output' => "Line 1\n--END COMMAND--"
        ]);
        
        $this->assertTrue($strategy->onResponse($response));
        $this->assertTrue($strategy->isComplete());
    }

    public function test_it_waits_for_sentinel_if_not_present(): void
    {
        $strategy = new FollowsResponseStrategy();
        
        $response = new Response([
            'response' => 'Follows',
            'output' => "Line 1"
        ]);
        
        $this->assertFalse($strategy->onResponse($response));
        $this->assertFalse($strategy->isComplete());
    }

    public function test_it_enforces_max_output_size(): void
    {
        $strategy = new FollowsResponseStrategy(10);
        
        $response = new Response([
            'response' => 'Follows',
            'output' => str_repeat("A", 20)
        ]);
        
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage("Follows response output size exceeds 10 bytes limit");
        
        $strategy->onResponse($response);
    }
}
