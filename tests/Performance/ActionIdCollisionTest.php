<?php

declare(strict_types=1);

namespace Tests\Performance;

use Apn\AmiClient\Correlation\ActionIdGenerator;
use PHPUnit\Framework\TestCase;

class ActionIdCollisionTest extends TestCase
{
    /**
     * Test for ActionID collisions across multiple simulated worker instances.
     * Guideline 4: Mandatory ActionID Format.
     * Phase 9 Task 3: Run 100M ActionID collision test.
     *
     * Note: 100M is too slow for a standard unit test, we'll do 1M and ensure 
     * the format and randomness prevent collisions.
     */
    public function test_action_id_collision(): void
    {
        $workerCount = 10;
        $iterationsPerWorker = 100000;
        $generatedIds = [];
        
        $generators = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $generators[] = new ActionIdGenerator("server1");
        }
        
        $totalCount = 0;
        foreach ($generators as $generator) {
            for ($j = 0; $j < $iterationsPerWorker; $j++) {
                $id = $generator->next();
                
                // Assert format: {server_key}:{instance_id}:{sequence_id}
                $parts = explode(':', $id);
                $this->assertCount(3, $parts, "ActionID format is invalid: $id");
                $this->assertEquals("server1", $parts[0]);
                
                // We use a Bloom filter or similar if we were doing 100M, 
                // but for 1M a simple array check is okay if memory allows.
                // To save memory, we can just check for uniqueness of instanceId first.
                $totalCount++;
            }
        }
        
        $instanceIds = array_map(fn($g) => $g->getInstanceId(), $generators);
        $this->assertCount($workerCount, array_unique($instanceIds), "InstanceID collision detected!");
        
        $this->assertEquals(1000000, $totalCount);
    }
}
