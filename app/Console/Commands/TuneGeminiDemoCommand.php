<?php

namespace App\Console\Commands;

use App\Services\Itinerary\TuneGeminiDemoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TuneGeminiDemoCommand extends Command
{
    protected $signature = 'travel:tune-gemini-demo
                            {--limit=15 : Number of synthetic training examples (5–20)}
                            {--dataset-only : Only write JSONL, do not call tuning API}
                            {--poll-seconds=20 : Poll interval while waiting for tune job}
                            {--max-wait=1800 : Max seconds to wait for tuning}
                            {--display-name=travel-route-model-v1 : Tuned model display name}
                            {--base-model=models/gemini-1.5-flash-001 : Base model for legacy tuning API}
                            {--epochs=4 : epoch_count}
                            {--keep-jsonl : Do not delete local JSONL after success}';

    protected $description = 'DEMO only: synthetic JSONL + legacy Gemini API tunedModels (usually 501)';

    public function handle(TuneGeminiDemoService $tuner): int
    {
        $limit = max(5, min(20, (int) $this->option('limit')));
        $displayName = (string) $this->option('display-name');
        $baseModel = (string) $this->option('base-model');
        $epochs = max(1, (int) $this->option('epochs'));
        $datasetOnly = (bool) $this->option('dataset-only');
        $pollSeconds = max(10, (int) $this->option('poll-seconds'));
        $maxWait = max(60, (int) $this->option('max-wait'));
        $keepJsonl = (bool) $this->option('keep-jsonl');

        $this->info('=== Gemini tune DEMO (synthetic examples, not production routes) ===');
        $this->warn('Google deprecated fine-tuning in Gemini API / AI Studio for current models.');
        $this->warn('This command still builds a dataset and attempts the legacy tunedModels API.');
        $this->newLine();

        $examples = $tuner->demoExamples($limit);
        $path = storage_path('app/dataset_tune_demo.jsonl');
        $written = $tuner->writeJsonl($path, $examples);
        $this->info("JSONL: {$path}");
        $this->info("Examples written: {$written}");
        $this->line('Sample input: '.mb_substr($examples[0]['input'], 0, 100).'…');
        $this->newLine();

        if ($datasetOnly) {
            $this->info('Dataset-only mode: skip API. Upload JSONL to Vertex / Agent Platform if you need real SFT.');
            $this->line('Next: keep file, or later set ITINERARY_FORMATTER_MODEL when you have a tuned model id.');

            return self::SUCCESS;
        }

        if (trim((string) config('services.gemini.key', '')) === '') {
            $this->error('GEMINI_API_KEY is empty in .env');

            return self::FAILURE;
        }

        $this->info("Starting tunedModels.create (base={$baseModel}, epochs={$epochs}, name={$displayName})…");
        $created = $tuner->createTunedModel($examples, $displayName, $baseModel, $epochs);

        if (! $created['ok']) {
            $this->error('Tune API failed: '.($created['error'] ?? 'unknown'));
            $this->newLine();
            $this->comment('Это ожидаемо, если на ключе больше нет fine-tuning (deprecation).');
            $this->comment('Датасет уже готов: '.$path);
            $this->comment('Для реального SFT сейчас нужен Vertex / Gemini Enterprise Agent Platform + GCS.');
            $this->comment('--dataset-only чтобы только собирать JSONL без вызова API.');

            return self::FAILURE;
        }

        $operation = $created['operation'];
        $tuned = $created['tuned_model'];
        $this->info('Create accepted.');
        if ($operation) {
            $this->line('Operation: '.$operation);
        }
        if ($tuned) {
            $this->line('Tuned model (early): '.$tuned);
        }
        $this->newLine();

        if ($operation === null || str_starts_with($operation, 'tunedModels/') && ! str_contains($operation, '/operations/')) {
            // Immediate model name without LRO
            if ($tuned || ($operation && str_starts_with($operation, 'tunedModels/'))) {
                $final = $tuned ?: $operation;
                $this->printSuccess($final, $path, $keepJsonl);

                return self::SUCCESS;
            }
        }

        $started = time();
        $this->info("Polling every {$pollSeconds}s (max {$maxWait}s)…");
        while (true) {
            $elapsed = time() - $started;
            if ($elapsed > $maxWait) {
                $this->error("Timeout after {$maxWait}s. Check operation in AI Studio / API: {$operation}");

                return self::FAILURE;
            }

            sleep($pollSeconds);
            $elapsed = time() - $started;
            $this->line("Обучение в процессе… Прошло {$elapsed} сек");

            if ($operation === null) {
                break;
            }

            $status = $tuner->getOperation($operation);
            if ($status['error']) {
                $this->error('Operation error: '.$status['error']);

                return self::FAILURE;
            }
            if ($status['done']) {
                $final = $status['tuned_model'] ?: $tuned;
                if ($final === null || $final === '') {
                    $this->error('Job done but tuned model id missing. Raw keys: '.implode(', ', array_keys($status['raw'])));
                    $this->line(json_encode($status['raw'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');

                    return self::FAILURE;
                }
                $this->printSuccess($final, $path, $keepJsonl);

                return self::SUCCESS;
            }
        }

        $this->error('No operation to poll.');

        return self::FAILURE;
    }

    private function printSuccess(string $tunedModelId, string $jsonlPath, bool $keepJsonl): void
    {
        $this->newLine();
        $this->info('=== SUCCESS ===');
        $this->info('Tuned model ID: '.$tunedModelId);
        $this->newLine();
        $this->comment('Пропиши в .env:');
        $this->line('ITINERARY_FORMATTER_MODEL='.$tunedModelId);
        $this->line('Затем: php artisan config:clear && supervisorctl restart …');
        $this->newLine();

        // Files API cleanup is N/A for inline training_data; still remove local JSONL unless kept.
        if (! $keepJsonl && is_file($jsonlPath)) {
            File::delete($jsonlPath);
            $this->line('Local JSONL deleted: '.$jsonlPath);
        } else {
            $this->line('Local JSONL kept: '.$jsonlPath);
        }
    }
}
