<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Exceptions\ProtocolException;
use PHPUnit\Framework\TestCase;

class ParserCorruptionTest extends TestCase
{
    public function test_it_recovers_from_injected_garbage_between_messages(): void
    {
        $parser = new Parser();
        
        $valid1 = "Response: Success\r\nActionID: 1\r\n\r\n";
        $garbage = "Some random garbage that is not valid AMI protocol data\r\n";
        $valid2 = "Response: Success\r\nActionID: 2\r\n\r\n";
        
        $parser->push($valid1);
        $parser->push($garbage);
        $parser->push($valid2);
        
        $msg1 = $parser->next();
        $this->assertInstanceOf(Response::class, $msg1);
        $this->assertEquals('1', $msg1->getActionId());
        
        // Next call should skip garbage because it doesn't end with \r\n\r\n
        // Actually, the parser implementation takes everything up to \r\n\r\n.
        // So the second 'next' will see "Some random garbage...\r\nResponse: Success\r\nActionID: 2"
        // And it should parse it, possibly with some junk headers or failing if it's too malformed.
        
        $msg2 = $parser->next();
        $this->assertInstanceOf(Response::class, $msg2);
        $this->assertEquals('2', $msg2->getActionId());
    }

    public function test_it_recovers_from_missing_delimiter_via_safety_limit(): void
    {
        $parser = new Parser();
        
        // Push garbage exceeding safety limit (MAX_FRAME_SIZE * 2)
        $limit = 65536;
        $garbage = str_repeat("G", $limit * 2 + 100);
        
        try {
            $parser->push($garbage);
        } catch (\Apn\AmiClient\Exceptions\ParserDesyncException $e) {
            $this->assertStringContainsString("safety limit", $e->getMessage());
        }
        
        // Parser should have cleared the buffer. Valid message should now work.
        $valid = "Response: Success\r\nActionID: recovery\r\n\r\n";
        $parser->push($valid);
        
        $msg = $parser->next();
        $this->assertInstanceOf(Response::class, $msg);
        $this->assertEquals('recovery', $msg->getActionId());
    }

    public function test_it_recovers_from_corrupted_frame_via_protocol_exception(): void
    {
        $parser = new Parser();
        
        // A "frame" that is too large
        $largeFrame = str_repeat("K: V\r\n", 20000) . "\r\n\r\n"; // > 64KB
        
        $parser->push($largeFrame);
        
        try {
            $parser->next();
            $this->fail("Should have thrown ProtocolException");
        } catch (ProtocolException $e) {
            $this->assertStringContainsString("exceeded", $e->getMessage());
        }
        
        // After ProtocolException, buffer should be cleared for next message (we fixed this earlier)
        $valid = "Response: Success\r\nActionID: recovery2\r\n\r\n";
        $parser->push($valid);
        
        $msg = $parser->next();
        $this->assertInstanceOf(Response::class, $msg);
        $this->assertEquals('recovery2', $msg->getActionId());
    }
}
