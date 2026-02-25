<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use Apn\AmiClient\Transport\WriteBuffer;
use Apn\AmiClient\Exceptions\BackpressureException;
use PHPUnit\Framework\TestCase;

class WriteBufferTest extends TestCase
{
    public function testPushAndContent(): void
    {
        $buffer = new WriteBuffer();
        $buffer->push("hello");
        $buffer->push(" world");

        $this->assertEquals("hello world", $buffer->content());
        $this->assertEquals(11, $buffer->size());
    }

    public function testAdvance(): void
    {
        $buffer = new WriteBuffer();
        $buffer->push("hello world");
        $buffer->advance(6);

        $this->assertEquals("world", $buffer->content());
        $this->assertEquals(5, $buffer->size());
    }

    public function testAdvanceBeyondSize(): void
    {
        $buffer = new WriteBuffer();
        $buffer->push("hello");
        $buffer->advance(10);

        $this->assertTrue($buffer->isEmpty());
        $this->assertEquals("", $buffer->content());
    }

    public function testMaxLimitEnforcement(): void
    {
        $buffer = new WriteBuffer(10);
        $buffer->push("12345");
        
        $this->expectException(BackpressureException::class);
        $buffer->push("678901");
    }

    public function testClear(): void
    {
        $buffer = new WriteBuffer();
        $buffer->push("data");
        $buffer->clear();

        $this->assertTrue($buffer->isEmpty());
        $this->assertEquals(0, $buffer->size());
    }
}
