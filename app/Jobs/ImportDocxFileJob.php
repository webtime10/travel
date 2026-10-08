<?php

namespace App\Jobs;

use App\Services\DocxLiteralImportService;
use App\Services\DocxSequentialParser;
use App\Services\ProductPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ImportDocxFileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public int $n,
        public string $path,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        DocxLiteralImportService $importService,
        DocxSequentialParser $parser,
        ProductPipelineStore $pipeline,
    ): void {
        if ($pipeline->importCancelled()) {
            $pipeline->finishImportIfEmpty();

            return;
        }

        $result = $importService->importFile($this->n, $this->path, $parser);
        $pipeline->recordImportResult($result);

        if ($pipeline->importCancelled()) {
            $pipeline->finishImportIfEmpty();

            return;
        }

        $next = $pipeline->shiftImportQueue();
        if ($next !== null) {
            self::dispatch($next['n'], $next['path']);

            return;
        }

        $pipeline->finishImportIfEmpty();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ImportDocxFileJob failed', [
            'n' => $this->n,
            'path' => $this->path,
            'message' => $e->getMessage(),
        ]);
        $pipeline = app(ProductPipelineStore::class);
        $pipeline->recordImportResult(['ok' => false, 'message' => $e->getMessage()]);
        if (! $pipeline->importCancelled()) {
            $next = $pipeline->shiftImportQueue();
            if ($next !== null) {
                self::dispatch($next['n'], $next['path']);
            } else {
                $pipeline->finishImportIfEmpty();
            }
        }
    }
}
