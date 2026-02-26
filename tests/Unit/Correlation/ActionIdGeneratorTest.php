<?php

declare(strict_types=1);

namespace tests\Unit\Correlation;

use Apn\AmiClient\Correlation\ActionIdGenerator;
use PHPUnit\Framework\TestCase;

class ActionIdGeneratorTest extends TestCase
{
    public function test_it_generates_action_ids_in_correct_format(): void
    {
        $generator = new ActionIdGenerator('server1', 'inst1');
        
        $this->assertEquals('server1:inst1:1', $generator->next());
        $this->assertEquals('server1:inst1:2', $generator->next());
        $this->assertEquals('server1:inst1:3', $generator->next());
    }

    public function test_it_generates_random_instance_id_if_none_provided(): void
    {
        $generator = new ActionIdGenerator('server1');
        $id = $generator->next();
        
        $this->assertMatchesRegularExpression('/^server1:[a-f0-9]{8}:1$/', $id);
    }

    public function test_action_ids_are_unique_across_generators(): void
    {
        $gen1 = new ActionIdGenerator('server1', 'inst1');
        $gen2 = new ActionIdGenerator('server2', 'inst1');
        $gen3 = new ActionIdGenerator('server1', 'inst2');

        $this->assertNotEquals($gen1->next(), $gen2->next());
        $this->assertNotEquals($gen1->next(), $gen3->next());
    }

    public function test_collision_resistance_simulation(): void
    {
        $ids = [];
        $generator = new ActionIdGenerator('server');
        
        for ($i = 0; $i < 1000; $i++) {
            $id = $generator->next();
            $this->assertArrayNotHasKey($id, $ids);
            $ids[$id] = true;
        }
        
        $this->assertCount(1000, $ids);
    }

    public function test_long_server_key_produces_bounded_action_id(): void
    {
        $longServerKey = str_repeat('node-segment-', 20);
        $generator = new ActionIdGenerator($longServerKey, 'worker-instance', 96);

        $id = $generator->next();
        $this->assertLessThanOrEqual(96, strlen($id));
        $this->assertCount(3, explode(':', $id));
    }

    public function test_bounded_action_ids_remain_unique_across_long_server_keys(): void
    {
        $serverA = str_repeat('server-a-', 24);
        $serverB = str_repeat('server-b-', 24);
        $instance = str_repeat('instance-', 12);

        $genA = new ActionIdGenerator($serverA, $instance, 96);
        $genB = new ActionIdGenerator($serverB, $instance, 96);

        $idA = $genA->next();
        $idB = $genB->next();

        $this->assertLessThanOrEqual(96, strlen($idA));
        $this->assertLessThanOrEqual(96, strlen($idB));
        $this->assertNotSame($idA, $idB);
    }
}
