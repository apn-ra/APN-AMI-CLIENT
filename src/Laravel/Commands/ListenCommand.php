<?php

declare(strict_types=1);

namespace Apn\AmiClient\Laravel\Commands;

use Apn\AmiClient\Cluster\AmiClientManager;
use Illuminate\Console\Command;

class ListenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ami:listen {--server= : The server key to listen to} {--all : Listen to all configured servers} {--once : Run a single loop iteration}';

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
        $all = $this->option('all');

        if (!$server && !$all) {
            $this->error('You must specify a --server or use --all');
            return 1;
        }

        $this->info('Starting AMI listen loop...');

        $manager->registerSignalHandlers();

        $runOnce = (bool) $this->option('once');
        $iterations = 0;

        while (true) {
            if ($all) {
                $manager->pollAll();
            } else {
                $manager->poll($server);
            }

            $iterations++;
            if ($runOnce && $iterations >= 1) {
                return 0;
            }
        }

        return 0;
    }
}
