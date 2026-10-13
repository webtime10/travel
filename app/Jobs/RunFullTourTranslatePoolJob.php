<?php

namespace App\Jobs;

use App\Services\FullTourRunStore;
use App\Services\Itinerary\BuildItineraryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunFullTourTranslatePoolJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    public function handle(FullTourRunStore $store, BuildItineraryService $builder): void
    {
        $data = $store->get($this->runId);
        if ($data === null) {
            return;
        }

        $days = $data['cleaned_days'] ?? [];
        if (! is_array($days) || $days === []) {
            $days = $data['curated_days'] ?? [];
        }
        if (! is_array($days) || $days === []) {
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => 'Нет пула дней для перевода. Сначала «Извлечь дни» / «Почистить пул».',
            ]);

            return;
        }

        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $steps[] = [
            'type' => 'think',
            'title' => 'Перевод пула на русский',
            'detail' => 'HE→RU для summary/places/body ('.count($days).' карточек)…',
        ];
        $store->patch($this->runId, [
            'status' => 'translating_pool',
            'mode' => 'translate_pool',
            'steps' => $steps,
        ]);

        try {
            $result = $builder->translateDayPool($days);
            $steps[] = [
                'type' => 'final',
                'title' => 'Пул переведён на русский',
                'detail' => 'полей: '.$result['translated_fields']
                    .' · '.$result['translate_ms'].'ms'
                    .(
                        $result['translated_fields'] === 0
                            ? "\nНечего переводить (иврита не найдено)."
                            : ''
                    ),
            ];
            $store->patch($this->runId, [
                'status' => 'done',
                'mode' => 'translate_pool',
                'steps' => $steps,
                'cleaned_days' => $result['cleaned_days'],
                'cleaned_text' => $result['cleaned_text'],
                'pool_translate_ms' => $result['translate_ms'],
                'reply' => null,
                'replies' => [],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunFullTourTranslatePoolJob failed', [
                'run_id' => $this->runId,
                'message' => $e->getMessage(),
            ]);
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(FullTourRunStore::class)->patch($this->runId, [
            'status' => 'failed',
            'error' => $exception?->getMessage() ?: 'Translate pool job failed',
        ]);
    }
}
