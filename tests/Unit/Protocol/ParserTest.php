<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Apn\AmiClient\Exceptions\ParserDesyncException;
use Apn\AmiClient\Exceptions\ProtocolException;
use Apn\AmiClient\Protocol\Banner;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Parser::class)]
class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function test_it_parses_initial_banner(): void
    {
        $this->parser->push("Asterisk Call Manager/5.0.1\r\n");
        $banner = $this->parser->next();
        
        $this->assertInstanceOf(Banner::class, $banner);
        $this->assertEquals("Asterisk Call Manager/5.0.1", $banner->getVersionString());
    }

    public function test_it_does_not_parse_banner_twice(): void
    {
        $this->parser->push("Asterisk Call Manager/5.0.1\r\n");
        $this->parser->next();
        
        // Second banner should NOT be parsed as Banner but probably as a malformed Response
        $this->parser->push("Asterisk Call Manager/2.0.0\r\n\r\n");
        $msg = $this->parser->next();
        
        $this->assertNotInstanceOf(Banner::class, $msg);
        $this->assertInstanceOf(Response::class, $msg);
    }

    public function test_it_parses_simple_response(): void
    {
        $data = "Response: Success\r\nActionID: 123\r\nMessage: Authentication accepted\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Response::class, $message);
        $this->assertTrue($message->isSuccess());
        $this->assertEquals('123', $message->getActionId());
        $this->assertEquals('Authentication accepted', $message->getMessageHeader());
        $this->assertEquals('Success', $message->getHeader('Response'));
    }

    public function test_it_parses_simple_event(): void
    {
        $data = "Event: PeerStatus\r\nPeer: PJSIP/101\r\nPeerStatus: Registered\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Event::class, $message);
        $this->assertEquals('PeerStatus', $message->getName());
        $this->assertEquals('PJSIP/101', $message->getHeader('Peer'));
    }

    public function test_it_handles_partial_reads(): void
    {
        $part1 = "Response: Success\r\n";
        $part2 = "ActionID: 456\r\n\r\n";
        
        $this->parser->push($part1);
        $this->assertNull($this->parser->next());
        
        $this->parser->push($part2);
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Response::class, $message);
        $this->assertEquals('456', $message->getActionId());
    }

    public function test_it_handles_multiple_messages_in_one_push(): void
    {
        $data = "Response: Success\r\nActionID: 1\r\n\r\nEvent: Ping\r\n\r\n";
        $this->parser->push($data);
        
        $msg1 = $this->parser->next();
        $msg2 = $this->parser->next();
        
        $this->assertInstanceOf(Response::class, $msg1);
        $this->assertInstanceOf(Event::class, $msg2);
    }

    public function test_it_normalizes_keys_to_lowercase(): void
    {
        $data = "RESPONSE: Success\r\nActionId: 789\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertEquals('Success', $message->getHeader('response'));
        $this->assertEquals('789', $message->getHeader('actionid'));
    }

    public function test_it_handles_duplicate_keys_as_arrays(): void
    {
        $data = "Event: Newchannel\r\nVariable: VAR1=VAL1\r\nVariable: VAR2=VAL2\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $variable = $message->getHeader('variable');
        $this->assertIsArray($variable);
        $this->assertCount(2, $variable);
        $this->assertEquals('VAR1=VAL1', $variable[0]);
        $this->assertEquals('VAR2=VAL2', $variable[1]);
    }

    public function test_it_handles_multi_line_follows_responses(): void
    {
        $data = "Response: Follows\r\nPrivilege: Command\r\nOutput line 1\r\nOutput line 2\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Response::class, $message);
        $privilege = $message->getHeader('privilege');
        
        $this->assertIsArray($privilege);
        $this->assertEquals('Command', $privilege[0]);
        $this->assertEquals('Output line 1', $privilege[1]);
        $this->assertEquals('Output line 2', $privilege[2]);
    }

    public function test_it_enforces_max_frame_size(): void
    {
        $largeData = str_repeat("A", 70000) . "\r\n\r\n";
        $this->parser->push($largeData);
        
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage("exceeded 65536 bytes limit");
        
        $this->parser->next();
    }

    public function test_it_recovers_from_desync_without_delimiter(): void
    {
        // Push too much data without delimiter (exceeding MAX_FRAME_SIZE * 2)
        $garbage = str_repeat("G", 150000);
        
        try {
            $this->parser->push($garbage);
        } catch (ParserDesyncException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), "safety limit") || 
                str_contains($e->getMessage(), "No message delimiter found")
            );
        }
        
        // Parser should be cleared, but let's test push after recovery
        $valid = "Response: Success\r\nActionID: recovery\r\n\r\n";
        $this->parser->push($valid);
        
        $message = $this->parser->next();
        $this->assertInstanceOf(Response::class, $message);
        $this->assertEquals('recovery', $message->getActionId());
    }

    public function test_it_recovers_from_desync_with_delimiter_at_end(): void
    {
        $garbage = str_repeat("G", 70000) . "\r\n\r\n";
        
        try {
            $this->parser->push($garbage);
            $this->parser->next();
            $this->fail("Should have thrown ProtocolException");
        } catch (ProtocolException $e) {
            $this->assertStringContainsString("exceeded", $e->getMessage());
        }
        
        // After recovery, pushing valid data
        $valid = "Response: Success\r\nActionID: recovery2\r\n\r\n";
        $this->parser->push($valid);
        
        $message = $this->parser->next();
        $this->assertInstanceOf(Response::class, $message);
        $this->assertEquals('recovery2', $message->getActionId());
    }

    public function test_it_handles_multiple_multi_line_entries_for_same_key(): void
    {
        $data = "Response: Follows\r\nActionID: multi\r\nLine 1\r\nLine 2\r\nLine 3\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $actionId = $message->getHeader('actionid');
        $this->assertIsArray($actionId);
        $this->assertEquals('multi', $actionId[0]);
        $this->assertEquals('Line 1', $actionId[1]);
        $this->assertEquals('Line 2', $actionId[2]);
        $this->assertEquals('Line 3', $actionId[3]);
    }

    public function test_it_handles_multi_line_on_already_array_header(): void
    {
        // Test case where a header is already an array due to duplicate keys, then a multi-line entry follows
        $data = "Event: Test\r\nVar: 1\r\nVar: 2\r\n3\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $var = $message->getHeader('var');
        $this->assertIsArray($var);
        $this->assertCount(3, $var);
        $this->assertEquals('1', $var[0]);
        $this->assertEquals('2', $var[1]);
        $this->assertEquals('3', $var[2]);
    }
    public function test_it_handles_multi_line_follows_on_actionid_without_breaking_it(): void
    {
        $data = "Response: Follows\r\nActionID: 123\r\nOutput line 1\r\nOutput line 2\r\n\r\n";
        $this->parser->push($data);
        
        $message = $this->parser->next();
        
        $this->assertInstanceOf(Response::class, $message);
        $this->assertEquals('123', $message->getActionId());
        
        // Output should be present in some key, let's see what happened
        $actionId = $message->getHeader('actionid');
        $this->assertIsArray($actionId);
        $this->assertCount(3, $actionId);
        $this->assertEquals('123', $actionId[0]);
        $this->assertEquals('Output line 1', $actionId[1]);
        $this->assertEquals('Output line 2', $actionId[2]);
    }
}
