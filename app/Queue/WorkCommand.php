<?php

namespace App\Queue;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Console\WorkCommand as BaseWorkCommand;
use Illuminate\Queue\Worker;

/**
 * Same as queue:work, but memory/timeout defaults come from config/queue.php.
 */
class WorkCommand extends BaseWorkCommand
{
    public function __construct(Worker $worker, Cache $cache)
    {
        parent::__construct($worker, $cache);

        $memory = max(128, (int) config('queue.worker.memory', 2048));
        $timeout = max(60, (int) config('queue.worker.timeout', 900));
        $maxJobs = max(0, (int) config('queue.worker.max_jobs', 1));

        $this->getDefinition()->getOption('memory')->setDefault((string) $memory);
        $this->getDefinition()->getOption('timeout')->setDefault((string) $timeout);
        $this->getDefinition()->getOption('max-jobs')->setDefault((string) $maxJobs);
    }
}
