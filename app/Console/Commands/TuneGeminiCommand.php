<?php

namespace App\Console\Commands;

use App\Services\Itinerary\VertexTuneGeminiService;
use Illuminate\Console\Command;
use Throwable;

class TuneGeminiCommand extends Command
{
    protected $signature = 'travel:tune-gemini
                            {--limit=1200 : Max products/routes to export}
                            {--dataset-only : Export JSONL only (no GCS / Vertex)}
                            {--skip-upload : Build JSONL + create tuning job URI without re-upload (use --gs-uri)}
                            {--gs-uri= : Existing gs:// URI (skips export+upload when set with --skip-upload)}
                            {--object=dataset.jsonl : Object name in GCS bucket}
                            {--display-name= : Tuned model display name}';

    protected $description = 'Export routes from products → GCS → Vertex AI supervised fine-tuning job';

    public function handle(VertexTuneGeminiService $vertex): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $datasetOnly = (bool) $this->option('dataset-only');
        $skipUpload = (bool) $this->option('skip-upload');
        $gsUriOpt = trim((string) $this->option('gs-uri'));
        $object = trim((string) $this->option('object')) ?: 'dataset.jsonl';
        $displayName = trim((string) $this->option('display-name'));
        if ($displayName === '') {
            $displayName = (string) config('services.vertex.tuned_display_name', 'travel-route-model-v1');
        }

        $this->info('=== Vertex AI Gemini supervised fine-tuning ===');
        $this->line('Project: '.config('services.vertex.project_id'));
        $this->line('Location: '.config('services.vertex.location'));
        $this->line('Bucket: '.config('services.vertex.gcs_bucket'));
        $this->line('Base model: '.config('services.vertex.base_model'));
        $this->newLine();

        $localPath = storage_path('app/dataset.jsonl');
        $gsUri = $gsUriOpt;

        try {
            if ($gsUri === '' || ! $skipUpload) {
                $this->info('[1/3] Экспорт из БД (products.meta / products.meta_ru)…');
                $export = $vertex->exportDataset($limit, $localPath);
                $this->info("Готово: {$export['count']} примеров → {$export['path']} (пропущено {$export['skipped']})");
                $this->newLine();

                if ($datasetOnly) {
                    $this->comment('dataset-only: GCS и Vertex пропущены.');
                    $this->line('Файл: '.$localPath);

                    return self::SUCCESS;
                }

                $this->info('[2/3] Загрузка в GCS…');
                $credentials = $vertex->credentialsPath();
                $this->line('Credentials: '.$credentials);
                $upload = $vertex->uploadToGcs($localPath, $object);
                $gsUri = $upload['gs_uri'];
                $this->info("Загружено: {$gsUri} ({$upload['bytes']} bytes)");
                $this->newLine();
            } else {
                $this->info('[1–2/3] Используем готовый URI: '.$gsUri);
                $this->newLine();
            }

            $this->info('[3/3] Запуск Vertex AI tuningJobs.create…');
            $creds = $vertex->bootstrapApplicationCredentials();
            $this->line('GOOGLE_APPLICATION_CREDENTIALS='.$creds);
            $job = $vertex->createTuningJob($gsUri, $displayName);
            $this->info('Tuning job started:');
            $this->line($job['name']);
            $this->newLine();
            $this->comment('Статус в консоли GCP / Vertex AI → Tuning.');
            $this->comment('После успеха укажи endpoint модели в ITINERARY_FORMATTER_MODEL (Vertex model resource name).');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
