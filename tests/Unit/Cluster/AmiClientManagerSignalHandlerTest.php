<?php

declare(strict_types=1);

namespace Tests\Unit\Cluster;

use Apn\AmiClient\Cluster\AmiClientManager;
use PHPUnit\Framework\TestCase;

final class AmiClientManagerSignalHandlerTest extends TestCase
{
    public function testSignalHandlerInvokesHookAndDoesNotExit(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl_signal not available');
        }

        $signalReceived = null;

        $manager = new AmiClientManager(signalHandler: function (int $signal) use (&$signalReceived): void {
            $signalReceived = $signal;
        });

        $manager->registerSignalHandlers();

        $this->assertNull($signalReceived);

        posix_kill(posix_getpid(), SIGTERM);

        $this->assertSame(SIGTERM, $signalReceived);
    }
}
