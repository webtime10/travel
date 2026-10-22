<?php

namespace App\Console\Commands;

use App\Services\RagDatabaseService;
use Illuminate\Console\Command;

class RagMigrateCommand extends Command
{
    protected $signature = 'travel:rag-migrate';

    protected $description = 'Create/upgrade PostgreSQL RAG schema (location_id, wipe old product tours once)';

    public function handle(RagDatabaseService $rag): int
    {
        $status = $rag->connectionStatus();
        if (! ($status['ok'] ?? false)) {
            $this->error('Cannot connect to pgsql_rag: '.($status['message'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info('Connected to '.$status['database'].'@'.$status['host'].':'.$status['port']);
        $result = $rag->migrateSchema();
        $this->info($result['message']);
        $this->line('Tables: '.implode(', ', $result['tables'] ?? []));

        return self::SUCCESS;
    }
}
