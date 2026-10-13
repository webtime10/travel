<?php

namespace App\Jobs;

use App\Services\FullTourRunStore;
use App\Services\Itinerary\BuildItineraryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunFullTourJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $message,
        public ?string $systemPrompt = null,
        public string $mode = 'preview',
    ) {}

    public function handle(BuildItineraryService $builder, FullTourRunStore $store): void
    {
        $mode = $this->mode === 'assemble' ? 'assemble' : 'preview';
        $existing = $store->get($this->runId) ?? [];

        if ($mode === 'preview') {
            $plan = is_array($existing['search_plan'] ?? null) ? $existing['search_plan'] : [];
            $vq = trim((string) ($plan['vector_query'] ?? ''));
            $locCount = is_array($plan['locations'] ?? null) ? count($plan['locations']) : 0;
            $store->patch($this->runId, [
                'status' => 'running',
                'mode' => 'preview',
                'steps' => [
                    [
                        'type' => 'think',
                        'title' => 'Поиск туров',
                        'detail' => $locCount > 0
                            ? 'Полная выборка по упоминаниям локаций плана ('.$locCount.' ориентиров)…'
                            : ($vq !== ''
                                ? 'По плану: '.mb_substr($vq, 0, 180).'…'
                                : 'Вектор → product_id → полный meta…'),
                    ],
                ],
            ]);
        }

        try {
            if ($mode === 'preview') {
                $plan = is_array($existing['search_plan'] ?? null) ? $existing['search_plan'] : [];
                $vectorQuery = trim((string) ($plan['vector_query'] ?? ''));
                $result = $builder->previewSources(
                    $this->message,
                    5,
                    $vectorQuery !== '' ? $vectorQuery : null,
                    $plan !== [] ? $plan : null,
                );
                $n = count($result['sources']);
                $store->patch($this->runId, [
                    'status' => 'done',
                    'mode' => 'preview',
                    'steps' => [
                        [
                            'type' => 'tool_result',
                            'title' => 'Найдено источников',
                            'detail' => $n.' тур(ов) по упоминаниям локаций · пул ~'.$result['pool_days']
                                .' дн. · search '.$result['search_ms'].'ms',
                        ],
                        [
                            'type' => 'final',
                            'title' => 'Сырьё готово',
                            'detail' => 'В аккордеоне — туры со строками маршрута вида «дата — места» по локациям плана (не голые упоминания).',
                        ],
                    ],
                    'reply' => null,
                    'sources' => $result['sources'],
                    'tours' => $result['sources'],
                    'search_plan' => $plan !== [] ? $plan : ($existing['search_plan'] ?? null),
                    'curated_days' => [],
                    'dropped_days' => [],
                    'brief_note' => null,
                    'preferred_regions' => is_array($plan['regions'] ?? null) ? $plan['regions'] : [],
                    'replies' => [],
                    'pool_days' => $result['pool_days'],
                    'search_ms' => $result['search_ms'],
                    'format_ms' => null,
                    'curate_ms' => null,
                    'total_ms' => $result['total_ms'],
                    'error' => null,
                ]);

                return;
            }

            $previewSources = is_array($existing['sources'] ?? null) ? $existing['sources'] : [];
            $assembleDays = is_array($existing['assemble_days'] ?? null) ? $existing['assemble_days'] : [];
            if ($assembleDays === []) {
                $assembleDays = is_array($existing['cleaned_days'] ?? null) ? $existing['cleaned_days'] : [];
            }
            if ($assembleDays === []) {
                $assembleDays = is_array($existing['curated_days'] ?? null) ? $existing['curated_days'] : [];
            }
            $variants = max(1, min(2, (int) ($existing['variants'] ?? 1)));

            if ($assembleDays === []) {
                throw new \RuntimeException('Нет карточек дней. Сначала «Извлечь дни» / «Почистить пул».');
            }

            $steps = is_array($existing['steps'] ?? null) ? $existing['steps'] : [];
            $steps[] = [
                'type' => 'think',
                'title' => 'Сборка маршрута',
                'detail' => 'Из пула '.count($assembleDays).' карточек'
                    .($variants > 1 ? ' · '.$variants.' варианта' : ''),
            ];
            $store->patch($this->runId, [
                'status' => 'running',
                'mode' => 'assemble',
                'steps' => $steps,
            ]);

            $replies = [];
            $result = null;
            for ($v = 1; $v <= $variants; $v++) {
                $prompt = $this->systemPrompt;
                if ($variants > 1) {
                    $hint = $v === 1
                        ? "\n\nВариант A: гео-блоки по brief; спокойнее, больше дней в первом приоритетном кусте из карточек, затем переезд. Без дублей и прыжков region."
                        : "\n\nВариант B: тоже только гео-блоки и места из пула, но другой баланс — раньше/длиннее второй куст (другой region из карточек). Без новых мест, без прыжков, без дублей.";
                    $prompt = trim((string) $prompt).$hint;
                }
                $result = $builder->assembleFromCuratedDays(
                    $this->message,
                    $assembleDays,
                    $previewSources,
                    $prompt,
                );
                $replies[] = [
                    'label' => $variants > 1 ? ($v === 1 ? 'Вариант A' : 'Вариант B') : 'Маршрут',
                    'text' => $result['reply'],
                    'format_ms' => $result['format_ms'],
                ];
            }

            $tourCount = is_array($result['tours'] ?? null) ? count($result['tours']) : 0;
            $asked = $result['constraints']['days'] ?? null;
            $filterNote = trim((string) ($result['assemble_filter_note'] ?? ''));
            $pref = is_array($result['preferred_regions'] ?? null)
                ? implode(', ', $result['preferred_regions'])
                : '';
            $steps[] = [
                'type' => 'tool_result',
                'title' => 'Пул для сборки',
                'detail' => 'в каталоге '.count($assembleDays).' → в Gemini '.($result['pool_days'] ?? '?').' карт.'
                    .($pref !== '' ? ' · приоритет: '.$pref : '')
                    .($asked ? ' · запрос '.$asked.' дн.' : '')
                    .($variants > 1 ? ' · вариантов: '.$variants : '')
                    .($filterNote !== '' ? "\n".$filterNote : ''),
            ];
            $steps[] = [
                'type' => 'final',
                'title' => $variants > 1 ? 'Варианты маршрута собраны' : 'Маршрут собран',
                'detail' => 'format '.$result['format_ms'].'ms · total '.$result['total_ms'].'ms',
            ];

            $store->patch($this->runId, [
                'status' => 'done',
                'mode' => 'assemble',
                'steps' => $steps,
                'reply' => $replies[0]['text'] ?? $result['reply'],
                'replies' => $replies,
                'sources' => $previewSources !== [] ? $previewSources : ($result['tours'] ?? []),
                'curated_days' => $existing['curated_days'] ?? [],
                'curated_text' => $existing['curated_text'] ?? null,
                'cleaned_days' => $existing['cleaned_days'] ?? [],
                'cleaned_text' => $existing['cleaned_text'] ?? null,
                'clean_note' => $existing['clean_note'] ?? null,
                'clean_ms' => $existing['clean_ms'] ?? null,
                'dropped_days' => $existing['dropped_days'] ?? [],
                'brief_note' => $existing['brief_note'] ?? null,
                'preferred_regions' => $existing['preferred_regions'] ?? ($result['preferred_regions'] ?? []),
                'tours' => $result['tours'],
                'product_id' => $result['product_id'],
                'model' => $result['model'],
                'meta_lang' => $result['meta_lang'],
                'estimated_days' => $result['estimated_days'] ?? null,
                'pool_days' => $result['pool_days'] ?? null,
                'search_ms' => $result['search_ms'],
                'format_ms' => $result['format_ms'],
                'curate_ms' => $existing['curate_ms'] ?? null,
                'total_ms' => $result['total_ms'],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunFullTourJob failed', [
                'run_id' => $this->runId,
                'mode' => $mode,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($store, $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->markFailed(app(FullTourRunStore::class), $exception?->getMessage() ?: 'Job failed');
    }

    private function markFailed(FullTourRunStore $store, string $message): void
    {
        $current = $store->get($this->runId) ?? [];
        if (($current['status'] ?? null) === 'failed') {
            return;
        }
        $steps = is_array($current['steps'] ?? null) ? $current['steps'] : [];
        $steps[] = [
            'type' => 'tool_error',
            'title' => 'Ошибка',
            'detail' => $message,
        ];
        $store->patch($this->runId, [
            'status' => 'failed',
            'steps' => $steps,
            'error' => $message,
        ]);
    }
}
