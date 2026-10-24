<?php

namespace App\Console\Commands;

use App\Jobs\IndexLocationJob;
use App\Models\Location;
use App\Services\LocationVectorPipelineStore;
use App\Services\RagDatabaseService;
use App\Services\RagLocationIndexer;
use Illuminate\Console\Command;

class IndexLocationsCommand extends Command
{
    protected $signature = 'travel:index-locations
                            {--pending : Only locations not yet in RAG}
                            {--fresh : Delete existing location vectors, then reindex all}
                            {--id= : One location id}
                            {--sync : Run in this process, no queue}';

    protected $description = 'Embed catalog locations into pgsql_rag (location_id). Use --fresh after reparse.';

    public function handle(
        RagDatabaseService $ragDb,
        RagLocationIndexer $indexer,
        LocationVectorPipelineStore $pipeline,
    ): int {
        $status = $ragDb->connectionStatus();
        if (! ($status['ok'] ?? false)) {
            $this->error('PostgreSQL RAG not connected: '.($status['message'] ?? ''));

            return self::FAILURE;
        }
        if (! ($status['schema_ready'] ?? false)) {
            $migrated = $ragDb->migrateSchema();
            $this->info($migrated['message'] ?? 'schema ok');
        }

        if ($this->option('fresh')) {
            $wiped = $ragDb->wipeLocationDocuments();
            $this->warn("Wiped location RAG docs: {$wiped}");
            if ($pipeline->running()) {
                $pipeline->cancel();
                $this->warn('Cancelled previous vector pipeline');
            }
        }

        $one = (int) $this->option('id');
        if ($one > 0) {
            $ids = [$one];
        } elseif ($this->option('pending') && ! $this->option('fresh')) {
            $ids = $this->pendingIds($ragDb);
        } else {
            $ids = Location::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($ids === []) {
            $this->info('Nothing to index. Already in RAG: '.$ragDb->indexedLocationCount());

            return self::SUCCESS;
        }

        $this->info('Locations to index: '.count($ids));

        if ($this->option('sync')) {
            $ok = 0;
            $fail = 0;
            foreach ($ids as $id) {
                $location = Location::query()->find($id);
                if (! $location) {
                    $this->warn('#'.$id.' missing');
                    $fail++;

                    continue;
                }
                try {
                    $result = $indexer->indexLocation($location);
                    $ok++;
                    $this->line('#'.$id.' chunks='.$result['chunks']);
                } catch (\Throwable $e) {
                    $fail++;
                    $this->error('#'.$id.' '.$e->getMessage());
                }
            }
            $this->info("Done ok={$ok} fail={$fail}. Indexed now: ".$ragDb->indexedLocationCount());

            return $fail > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($pipeline->running()) {
            $this->error('Vector pipeline already running');

            return self::FAILURE;
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            IndexLocationJob::dispatch($first);
        }
        $this->info('Queued '.count($ids).'. Worker: php artisan queue:work-forever');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function pendingIds(RagDatabaseService $ragDb): array
    {
        $ids = Location::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $indexed = array_fill_keys($ragDb->indexedLocationIds($ids), true);

        return array_values(array_filter($ids, fn (int $id) => ! isset($indexed[$id])));
    }
}
