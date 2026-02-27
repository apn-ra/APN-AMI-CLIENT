<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel\Commands;

use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Core\Contracts\TransportInterface;
use Apn\AmiClient\Exceptions\InvalidConfigurationException;
use Illuminate\Console\Command;

class ListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ami:listen {--server= : The server key to listen to} {--all : Listen to all configured servers} {--once : Run a single loop iteration} {--max-iterations= : Run a bounded number of loop iterations} {--tick-timeout-ms= : Listen loop selector wait in milliseconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the AMI client worker loop';

    /**
     * Execute the console command.
     */
    public function handle(AmiClientManager $manager): int
    {
        $server = $this->option('server');
        $all = (bool) $this->option('all');

        if (!$server && !$all) {
            $this->error('You must specify a --server or use --all');
            return 1;
        }

        try {
            $tickTimeoutMs = $this->resolveTickTimeoutMs();
            $maxIterations = $this->resolveMaxIterations();
        } catch (InvalidConfigurationException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info('Starting AMI listen loop...');

        $manager->registerSignalHandlers();

        $runOnce = (bool) $this->option('once');
        $iterations = 0;

        while (true) {
            $iterationStartedAtMs = $this->nowMs();
            if ($all) {
                $summary = $manager->tickAll($tickTimeoutMs);
            } else {
                $summary = $manager->tick((string) $server, $tickTimeoutMs);
            }
            $iterationElapsedMs = max(0.0, $this->nowMs() - $iterationStartedAtMs);

            $iterations++;
            if ($runOnce && $iterations >= 1) {
                return 0;
            }
            if ($maxIterations !== null && $iterations >= $maxIterations) {
                return 0;
            }

            if (!$summary->hasProgress()) {
                $this->applyCadence($tickTimeoutMs, $iterationElapsedMs);
                $manager->recordIdleYield($all ? 'all' : (string) $server);
            }
        }

        return 0;
    }

    private function resolveTickTimeoutMs(): int
    {
        $raw = $this->option('tick-timeout-ms');
        if ($raw === null || $raw === false || $raw === '') {
            $raw = $this->configuredTickTimeoutMs();
        }

        if (!is_numeric($raw)) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid listen loop cadence: tick-timeout-ms must be an integer between 1 and %d.',
                TransportInterface::MAX_TICK_TIMEOUT_MS
            ));
        }

        $timeout = (int) $raw;
        if ($timeout < 1 || $timeout > TransportInterface::MAX_TICK_TIMEOUT_MS) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid listen loop cadence: tick-timeout-ms must be between 1 and %d.',
                TransportInterface::MAX_TICK_TIMEOUT_MS
            ));
        }

        return $timeout;
    }

    private function resolveMaxIterations(): ?int
    {
        $raw = $this->option('max-iterations');
        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new InvalidConfigurationException('Invalid max-iterations: value must be a positive integer.');
        }

        $value = (int) $raw;
        if ($value < 1) {
            throw new InvalidConfigurationException('Invalid max-iterations: value must be >= 1.');
        }

        return $value;
    }

    protected function configuredTickTimeoutMs(): mixed
    {
        if ($this->laravel === null || !$this->laravel->bound('config')) {
            return 25;
        }

        return $this->laravel['config']->get('ami-client.listen.tick_timeout_ms', 25);
    }

    protected function nowMs(): float
    {
        return microtime(true) * 1000;
    }

    protected function sleepMs(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    private function applyCadence(int $cadenceMs, float $elapsedMs): void
    {
        if ($cadenceMs <= 0) {
            return;
        }

        $elapsedWholeMs = (int) floor($elapsedMs);
        $remainingMs = $cadenceMs - $elapsedWholeMs;
        if ($remainingMs <= 0) {
            return;
        }

        $this->sleepMs($remainingMs);
    }
}
