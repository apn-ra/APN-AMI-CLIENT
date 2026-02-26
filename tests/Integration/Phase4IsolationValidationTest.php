<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\AmiClient;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationManager;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\ParserDesyncException;
use Apn\AmiClient\Health\HealthStatus;
use Apn\AmiClient\Protocol\Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class Phase4IsolationValidationTest extends TestCase
{
    public function test_parser_corruption_isolation(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $manager = new AmiClientManager(logger: $logger);

        $transport1 = $this->createMock(TransportInterface::class);
        $transport1->method('isConnected')->willReturn(true);
        $parser1 = $this->createMock(Parser::class);
        
        // Simulate corruption in client 1
        $parser1->method('next')->willThrowException(new ParserDesyncException("Corrupted stream"));

        $client1 = new AmiClient(
            'node1',
            $transport1,
            new CorrelationManager(new ActionIdGenerator('node1'), new CorrelationRegistry()),
            parser: $parser1,
            logger: $logger
        );

        $transport2 = $this->createMock(TransportInterface::class);
        $transport2->method('isConnected')->willReturn(true);
        $parser2 = $this->createMock(Parser::class);
        // Client 2 is healthy
        $parser2->method('next')->willReturn(null);

        $client2 = new AmiClient(
            'node2',
            $transport2,
            new CorrelationManager(new ActionIdGenerator('node2'), new CorrelationRegistry()),
            parser: $parser2,
            logger: $logger
        );

        $manager->addClient('node1', $client1);
        $manager->addClient('node2', $client2);

        // Expect an error log for node1 from AmiClient itself
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Protocol error or parser desync'), $this->callback(function($context) {
                return $context['server_key'] === 'node1' && str_contains($context['exception'], 'Corrupted stream');
            }));

        // Run tickAll
        $manager->tickAll();

        // Verify client 2 was still processed (it transitions to healthy as no credentials set)
        $this->assertEquals(HealthStatus::READY, $client2->getHealthStatus());
    }
}
