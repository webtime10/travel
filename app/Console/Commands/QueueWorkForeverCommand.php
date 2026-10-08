<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Keeps indexing alive: one job per process cycle so PHP memory is released.
 */
class QueueWorkForeverCommand extends Command
{
    protected $signature = 'queue:work-forever
                            {connection? : The name of the queue connection to work}
                            {--queue= : The names of the queues to work}
                            {--sleep=2 : Seconds to sleep when no job is available}';

    protected $description = 'Run queue:work in a loop (1 job per cycle) to avoid Memory limit exceeded';

    public function handle(): int
    {
        $memory = max(128, (int) config('queue.worker.memory', 2048));
        $timeout = max(60, (int) config('queue.worker.timeout', 900));
        $connection = $this->argument('connection');
        $queue = $this->option('queue');
        $sleep = max(1, (int) $this->option('sleep'));

        $this->info("queue:work-forever started (memory={$memory}MB, timeout={$timeout}s, max-jobs=1 per cycle)");
        $this->warn('Stop with Ctrl+C');

        while (true) {
            $params = [
                '--memory' => $memory,
                '--timeout' => $timeout,
                '--max-jobs' => 1,
                '--sleep' => $sleep,
            ];
            if (is_string($queue) && $queue !== '') {
                $params['--queue'] = $queue;
            }

            $args = [];
            if (is_string($connection) && $connection !== '') {
                $args['connection'] = $connection;
            }

            $this->call('queue:work', array_merge($args, $params));
        }
    }
}
