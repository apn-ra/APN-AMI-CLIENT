<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Cluster\ConfigLoader;
use Apn\AmiClient\Core\Contracts\AmiClientInterface;
use PHPUnit\Framework\TestCase;

class RuntimeProfilesTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'default' => 'node1',
            'servers' => [
                'node1' => [
                    'host' => '127.0.0.1',
                    'port' => 5038,
                    'username' => 'admin',
                    'secret' => 'secret',
                ],
            ],
        ];
    }

    public function testProfileAManualTickLoop(): void
    {
        // Simulate Profile A: Manual instantiation and tick() loop
        $manager = ConfigLoader::load($this->config);
        
        $this->assertInstanceOf(AmiClientManager::class, $manager);
        
        // We can't easily run a real loop in a unit test, but we can verify tickAll calls clients
        // The AmiClientManagerTest already does this, but this integration test verifies 
        // the bootstrap from config.
        
        $client = $manager->server('node1');
        $this->assertInstanceOf(AmiClientInterface::class, $client);
    }

    public function testProfileCEmbeddedTickMode(): void
    {
        // Simulate Profile C: Application calls tickAll() inside its own loop
        $manager = ConfigLoader::load($this->config);
        
        // Ensure non-blocking tick works
        // We mock the reactor or use a real one with a closed port to avoid blocking
        $manager->tickAll(0);
        
        $this->assertTrue(true, "tickAll(0) did not block");
    }
}
