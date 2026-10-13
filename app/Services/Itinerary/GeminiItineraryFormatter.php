<?php

namespace App\Services\Itinerary;

use App\Contracts\ItineraryFormatter;
use App\Services\GeminiKeyPool;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Formats full tour JSON(s) into a day-by-day itinerary via Gemini.
 */
class GeminiItineraryFormatter implements ItineraryFormatter
{
    public function __construct(private GeminiKeyPool $keys) {}

    public function format(array $tourMeta, array $brief = [], ?string $systemPrompt = null): array
    {
        return $this->formatPool([
            [
                'product_id' => (int) ($brief['product_id'] ?? 0),
                'model' => $brief['model'] ?? null,
                'estimated_days' => (int) ($brief['estimated_days_in_tour'] ?? 0),
                'full_tour' => $tourMeta,
            ],
        ], $brief, $systemPrompt);
    }

    /**
     * Assemble itinerary ONLY from curated day cards (no raw Word dumps).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, mixed>  $brief
     * @return array{text: string, structured: null}
     */
    public function formatFromDayPool(array $days, array $brief = [], ?string $systemPrompt = null): array
    {
        if ($days === []) {
            throw new RuntimeException('Empty curated day pool');
        }

        $model = trim((string) config('services.itinerary.formatter_model', config('services.gemini.model')));
        $timeout = max(30, (int) config('services.gemini.chat_timeout', 900));
        $baseSystem = trim((string) ($systemPrompt ?: $this->defaultDayPoolSystemPrompt()));
        $system = trim($baseSystem."\n\n".$this->directorAssembleRules());
        $lang = (string) ($brief['constraints']['answer_lang'] ?? 'ru');
        $askedDays = $brief['constraints']['days'] ?? null;
        $nature = ! empty($brief['constraints']['nature']) || ! empty($brief['constraints']['no_big_cities']);

        $compactDays = [];
        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $body = trim((string) ($day['body'] ?? ''));
            if (mb_strlen($body) > 2500) {
                $body = mb_substr($body, 0, 2500).'…';
            }
            $row = [
                'id' => $day['id'] ?? null,
                'places' => $day['places'] ?? [],
                'region' => $day['region'] ?? 'other',
                'summary' => $day['summary'] ?? '',
                'kind' => $day['kind'] ?? 'program',
                'body' => $body,
                'model' => $day['model'] ?? null,
                'product_id' => $day['product_id'] ?? null,
            ];
            if (isset($day['lat'], $day['lng']) && $day['lat'] !== null && $day['lng'] !== null) {
                $row['lat'] = (float) $day['lat'];
                $row['lng'] = (float) $day['lng'];
            }
            if (isset($day['geo_span_km']) && is_numeric($day['geo_span_km'])) {
                $row['geo_span_km'] = (float) $day['geo_span_km'];
            }
            if (array_key_exists('geo_ok', $day)) {
                $row['geo_ok'] = (bool) $day['geo_ok'];
            }
            $compactDays[] = $row;
        }

        $maxJump = (float) ($brief['max_day_jump_km'] ?? config('services.nominatim.max_day_jump_km', 120));
        $maxSpan = (float) ($brief['max_day_span_km'] ?? config('services.nominatim.max_day_span_km', 70));

        $instructions = [
            'Собери ОДИН маршрут «как у директора»: кратко, по дням, из curated_days. Правила универсальны для любого направления из brief.',
            'Ориентир — brief/опросник (хаб, регионы, даты, nature). Не привязывайся к одному озеру «из привычки».',
            'Каждый день = ОДНА карточка (или subset её places). Места ТОЛЬКО из карточек — ничего не выдумывай.',
            'ГЕОГРАФИЯ: блоки по близости. Несколько дней подряд в одном кусте (одно озеро/долина/город из карточек) → явный переезд → следующий куст → … → выезд.',
            'КООРДИНАТЫ: у карточек есть lat/lng. Завтрашняя карточка должна быть рядом с сегодняшней (обычно ≤ '.$maxJump.' км по прямой). Иначе обязателен день transfer/arrival/departure.',
            'В ОДНОМ дне не смешивай точки дальше ~'.$maxSpan.' км (смотри geo_span_km / geo_ok). Не ставь «завтрак на севере, обед на юге».',
            'Если в curated_days есть карточки ≥2 разных region (кроме other) и они релевантны brief — используй МИНИМУМ 2 куста в маршруте. Не залипай на одном region на все target_days.',
            'Побочный region (есть в каталоге, но не главный по brief) — максимум 1 день (или 0). Не раздувай его на 2–3 дня.',
            'Завтра рядом с сегодня. ЗАПРЕЩЕНЫ прыжки между далёкими region без дня transfer/arrival/departure.',
            'Один день = один region (поле карточки). Смена региона — только через arrival/transfer/departure.',
            'Не упаковывай в ОДИН день места из далёких кустов. Переезд между кустами — отдельный день или kind=transfer.',
            'БЕЗ ДУБЛЕЙ: не повторять те же places в соседних днях и не ставить почти одинаковые карточки подряд.',
            'В строке дня — только КОНКРЕТНЫЕ places из карточки (город/озеро/перевал с именем). ЗАПРЕЩЕНО писать общие ярлыки: «Доломитовые Альпы», «озёра Доломит», «озеро Гарда», «водопады Рива» без конкретных точек из карточки.',
            'Если в brief.preferred_regions указаны регионы — распредели дни по ним блоками (не выброси второй куст).',
            'Arrival: хаб из brief + переезд в ПЕРВЫЙ приоритетный куст программы (не раздувай побочное озеро). Departure: по пути к аэропорту/хабу.',
            'Приоритет карточек — ближе к brief; побочное отсекай.',
            'Порядок: arrival → program-блоки → departure. Календарь = date_start → date_end.',
            'Язык = '.$lang.'. Для ru — без иврита. Формат ТОЛЬКО «ДД.ММ — места».',
        ];
        if ($nature) {
            $instructions[] = 'nature/no_big_cities: без шопинга, аутлетов, парков развлечений/аквапарков и городского sightseeing как основных дней; приоритет природа/прогулки из карточек под brief.';
        }

        $payloadUser = [
            'brief' => $brief,
            'curated_days' => $compactDays,
            'target_days' => $askedDays,
            'instructions' => $instructions,
        ];

        $userText = 'Каталог RU-дней + опросник. Собери директорский маршрут ТОЛЬКО из curated_days. Язык: '.$lang.".\n\n"
            .json_encode($payloadUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->generate($model, $timeout, $system, $userText);
    }

    public function formatPool(array $tours, array $brief = [], ?string $systemPrompt = null): array
    {
        if ($tours === []) {
            throw new RuntimeException('Empty tours pool for formatter');
        }

        $model = trim((string) config('services.itinerary.formatter_model', config('services.gemini.model')));
        $timeout = max(30, (int) config('services.gemini.chat_timeout', 900));
        $system = trim((string) ($systemPrompt ?: $this->defaultSystemPrompt()));
        $lang = (string) ($brief['constraints']['answer_lang'] ?? 'ru');
        $askedDays = $brief['constraints']['days'] ?? null;

        $payloadUser = [
            'brief' => $brief,
            'tours_from_database' => $tours,
            'target_days' => $askedDays,
            'instructions' => [
                'Составь ОДИН маршрут на target_days дней (если указано), склеивая дни из НЕСКОЛЬКИХ туров в tours_from_database.',
                'Места ТОЛЬКО из blocks этих туров. Можно переводить названия. Не выдумывай точки «из головы» / из интернета.',
                'Язык ответа = '.$lang.'. Для ru — ТОЛЬКО русский/латиница в скобках. Запрещены любые буквы иврита.',
                'Календарь = brief.constraints.date_start → date_end.',
                'Бери дни из разных туров пула, без повторов одних точек подряд.',
                'Каждый день (кроме чисто прилёта/вылета) — 2–4 точки программы, не одна строка «Аэропорт».',
                'no_big_cities/nature: без шопинга/аутлетов/городского sightseeing.',
                'ИГНОРИРУЙ: оглавление/Содержание, FAQ, дисклеймеры (Гимпель), рекламу аренды авто, «Советы по вождению», номера страниц в конце строк.',
                'Бери только дневные программы (день недели + дата + места). Не копируй юридические абзацы.',
                'Формат ТОЛЬКО строки «ДД.ММ — места». Без предисловий, без «Источники», без «В базе меньше дней», без итогов — это добавит система.',
            ],
        ];

        $userText = 'Опросник + пул полных туров из базы. Собери один маршрут. Язык: '.$lang.".\n\n"
            .json_encode($payloadUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (mb_strlen($userText) > 900000) {
            $userText = mb_substr($userText, 0, 900000)."\n…[truncated]";
        }

        return $this->generate($model, $timeout, $system, $userText);
    }

    /**
     * @return array{text: string, structured: null}
     */
    private function generate(string $model, int $timeout, string $system, string $userText): array
    {
        if (mb_strlen($userText) > 900000) {
            $userText = mb_substr($userText, 0, 900000)."\n…[truncated]";
        }

        $text = $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $system, $userText) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':generateContent?key='.$apiKey;

            $body = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $userText]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 8192,
                ],
            ];
            if ($system !== '') {
                $body['systemInstruction'] = [
                    'parts' => [['text' => mb_substr($system, 0, 20000)]],
                ];
            }
            if (str_starts_with($model, 'gemini-3')) {
                $body['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
            }

            $wait = 2;
            $last = 'Itinerary formatter failed';
            for ($i = 1; $i <= 4; $i++) {
                $response = Http::timeout($timeout)->acceptJson()->asJson()->post($url, $body);
                if ($response->successful()) {
                    $parts = $response->json('candidates.0.content.parts') ?? [];
                    $chunks = [];
                    if (is_array($parts)) {
                        foreach ($parts as $part) {
                            if (is_array($part) && isset($part['text'])) {
                                $chunks[] = (string) $part['text'];
                            }
                        }
                    }
                    $out = trim(implode("\n", $chunks));
                    if ($out === '') {
                        throw new RuntimeException('Empty itinerary formatter reply');
                    }

                    return $out;
                }
                $last = 'Itinerary Gemini HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400);
                if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $i < 4) {
                    sleep($wait);
                    $wait = min(30, $wait * 2);

                    continue;
                }
                throw new RuntimeException($last);
            }

            throw new RuntimeException($last);
        });

        return [
            'text' => $text,
            'structured' => null,
        ];
    }

    private function defaultDayPoolSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — составитель маршрутов «как директор»: кратко и по делу только из curated_days.
Формат ответа: только строки «ДД.ММ — места».
PROMPT;
    }

    private function directorAssembleRules(): string
    {
        return <<<'PROMPT'
ДИРЕКТОРСКИЕ ПРАВИЛА (для любого направления из brief):
1) Только места из curated_days. Не выдумывай.
2) Гео-блоки: несколько дней в одном кусте → переезд → следующий куст. Завтра рядом с сегодня (смотри lat/lng). Без прыжков region. Один день не смешивает далёкие кусты (geo_ok / geo_span_km).
3) Если в каталоге ≥2 релевантных region — возьми ≥2 куста. Побочный region ≤1 день.
4) Без дублей places/дней. Конкретные названия из карточек.
5) Следуй brief. Arrival/departure к хабу; выезд по пути, без скачка из дальнего куста.
PROMPT;
    }

    private function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты собираешь ОДИН маршрут из нескольких полных туров (tours_from_database).

ПРАВИЛА:
1) Места только из JSON туров. Перевод на язык answer_lang обязателен (ru — без иврита).
2) Если target_days = 10, а один тур короткий — добери дни из 2–5 других туров пула. Не выдумывай локации.
3) Календарь = date_start/date_end опросника.
4) Без повторов одних и тех же точек каждый день. Логичный географический порядок по возможности.
5) no_big_cities/nature — без шопинга и городского sightseeing.
6) Игнорируй FAQ, дисклеймеры, оглавление, рекламу аренды авто.
7) Формат: «14.08 — место1, место2». Кратко.
PROMPT;
    }
}
