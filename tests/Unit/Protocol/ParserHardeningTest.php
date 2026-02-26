<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Apn\AmiClient\Exceptions\ParserDesyncException;
use Apn\AmiClient\Exceptions\ProtocolException;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Parser::class)]
class ParserHardeningTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser(maxFrameSize: 65536);
    }

    public function test_it_handles_newline_only_delimiter(): void
    {
        $data = "Event: PeerStatus\nPeer: PJSIP/101\nPeerStatus: Registered\n\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Event::class, $message);
        $this->assertEquals('PeerStatus', $message->getName());
        $this->assertEquals('PJSIP/101', $message->getHeader('peer'));
    }

    public function test_it_handles_mixed_line_endings_with_newline_delimiter(): void
    {
        $data = "Event: PeerStatus\r\nPeer: PJSIP/101\r\nPeerStatus: Registered\n\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Event::class, $message);
        $this->assertEquals('PeerStatus', $message->getName());
    }

    public function test_it_handles_newline_line_endings_with_rn_delimiter(): void
    {
        $data = "Event: PeerStatus\nPeer: PJSIP/101\nPeerStatus: Registered\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Event::class, $message);
        $this->assertEquals('PeerStatus', $message->getName());
    }

    public function test_it_enforces_buffer_cap(): void
    {
        $smallParser = new Parser(bufferCap: 65540, maxFrameSize: 65536);
        
        $this->expectException(ParserDesyncException::class);
        $this->expectExceptionMessage("Parser buffer exceeded safety limit");
        
        $smallParser->push(str_repeat("A", 65541));
    }

    public function test_it_recovers_after_buffer_cap_violation(): void
    {
        $smallParser = new Parser(bufferCap: 65540, maxFrameSize: 65536);
        
        try {
            $smallParser->push(str_repeat("A", 65541));
        } catch (ParserDesyncException) {
            // Expected
        }
        
        $valid = "Response: Success\n\n";
        $smallParser->push($valid);
        $this->assertInstanceOf(Response::class, $smallParser->next());
    }

    public function test_it_rejects_invalid_buffer_cap_to_frame_size_relationship(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('bufferCap=65539');
        $this->expectExceptionMessage('maxFrameSize=65536');

        new Parser(bufferCap: 65539, maxFrameSize: 65536);
    }

    public function test_it_rejects_invalid_relationship_before_runtime_parsing_begins(): void
    {
        $constructed = false;

        try {
            $parser = new Parser(bufferCap: 70003, maxFrameSize: 70000);
            $constructed = true;
            $parser->push("Response: Success\r\n\r\n");
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertFalse($constructed);
            $this->assertStringContainsString('bufferCap=70003', $e->getMessage());
            $this->assertStringContainsString('maxFrameSize=70000', $e->getMessage());
        }
    }

    public function test_it_recovers_after_no_delimiter_violation(): void
    {
        // 131072 is MAX_FRAME_SIZE * 2
        $this->expectException(ParserDesyncException::class);
        $this->expectExceptionMessage("No message delimiter found");
        
        $this->parser->push(str_repeat("A", 140000));
    }

    public function test_it_handles_desync_recovery_with_newline_delimiter(): void
    {
        $garbage = str_repeat("G", 70000) . "\n\n";
        
        try {
            $this->parser->push($garbage);
            $this->parser->next();
            $this->fail("Should have thrown ProtocolException");
        } catch (ProtocolException $e) {
            $this->assertStringContainsString("exceeded", $e->getMessage());
        }
        
        // After recovery, pushing valid data with \n\n
        $valid = "Response: Success\nActionID: recovery-n\n\n";
        $this->parser->push($valid);
        
        $message = $this->parser->next();
        $this->assertInstanceOf(Response::class, $message);
        $this->assertEquals('recovery-n', $message->getActionId());
    }
}
