<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\DocxLiteralImportService;
use App\Services\DocxSequentialParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportDocxLiteralCommand extends Command
{
    protected $signature = 'travel:import-docx-literal
                            {--limit=0 : Max docs (0 = all)}
                            {--fresh : Delete all products before import}
                            {--only= : Comma-separated model numbers, e.g. 1,2,5}';

    protected $description = 'Import DOCX files 1:1 into Hebrew-only product meta (name=filename, model=1..n)';

    public function handle(DocxLiteralImportService $importService, DocxSequentialParser $parser): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $fresh = (bool) $this->option('fresh');
        $onlyOpt = trim((string) $this->option('only'));
        $only = $onlyOpt !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $onlyOpt))))
            : [];

        $files = $importService->enumerateFiles();
        if ($files === []) {
            $this->error('No .docx in '.$importService->docDirectory());

            return self::FAILURE;
        }

        if ($fresh) {
            $this->warn('Deleting all products…');
            DB::table('category_product')->delete();
            DB::table('product_descriptions')->delete();
            Product::query()->delete();
        }

        $importService->ensureLanguagesAndCategory();

        $n = 0;
        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $row) {
            $n = $row['n'];
            if ($only !== [] && ! in_array($n, $only, true)) {
                continue;
            }
            if ($limit > 0 && $only === [] && $imported + $failed >= $limit) {
                break;
            }

            $this->info("[{$row['model']}] {$row['source']}");
            $result = $importService->importFile($n, $row['path'], $parser);
            if ($result['ok']) {
                $this->info(sprintf(
                    '  → product #%d model=%s blocks=%d',
                    $result['product_id'],
                    $result['model'],
                    $result['blocks'] ?? 0
                ));
                $imported++;
            } else {
                $this->error('  → '.$result['message']);
                $failed++;
            }
        }

        $this->info('Done. files='.count($files)." imported={$imported} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }
}
