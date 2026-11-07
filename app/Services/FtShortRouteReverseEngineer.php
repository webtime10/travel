<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\FtShortRoute;

/**
 * Обратный разбор FT Short — мастер-промпт авторской логики (архетип / DNA / FineTune).
 * schema v5: trip_archetype + macro + micro + fine_tune.
 *
 * Один рабочий промпт — на русском (админка).
 * Провайдер разбора: gemini | claude.
 * Ответ модели: string values на иврите → reverse_principle; RU-зеркало — отдельным переводом.
 */
class FtShortRouteReverseEngineer
{
    public const SCHEMA_VERSION = 5;

    /** @deprecated Зеркало HE больше не используется; оставлен для старых ключей в БД. */
    public const PROMPT_SETTING_KEY = 'travel.reverse_engineer.ft_short_route_prompt_he_v5';

    /** Единственный рабочий промпт (русский). */
    public const PROMPT_SETTING_KEY_RU = 'travel.reverse_engineer.ft_short_route_prompt_ru_v7';

    public function __construct(
        private GeminiJsonClient $gemini,
        private ClaudeJsonClient $claude,
    ) {}

    /** В админке всегда русский промпт (и при UI he, и при UI ru). */
    public static function resolvePromptForUi(string $uiLang = 'ru'): string
    {
        return self::resolvePromptRu();
    }

    public static function resolvePrompt(): string
    {
        return self::resolvePromptRu();
    }

    /** @deprecated alias → русский рабочий промпт */
    public static function resolvePromptHe(): string
    {
        return self::resolvePromptRu();
    }

    public static function resolvePromptRu(): string
    {
        $stored = trim((string) AppSetting::getValue(self::PROMPT_SETTING_KEY_RU, ''));

        // Ручное «Сохранить» — свято: если схема на месте, не затираем (метку LANGUAGE_* можно удалить).
        if ($stored !== ''
            && str_contains($stored, 'trip_archetype')
            && str_contains($stored, 'signature_wildcard')
            && str_contains($stored, 'fine_tune')
            && ! self::promptNeedsForcedUpgrade($stored)
        ) {
            return $stored;
        }

        $fresh = self::defaultPromptRu();
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    /** Старые заводские версии — один раз подтянуть дефолт. Свой сохранённый текст без LANGUAGE_* не трогаем. */
    private static function promptNeedsForcedUpgrade(string $stored): bool
    {
        foreach ([
            'LANGUAGE_HE_ONLY_V5',
            'LANGUAGE_RU_UI_V5',
            'LANGUAGE_RU_INSTR_HE_OUT_V5',
            'LANGUAGE_RU_INSTR_HE_OUT_V6',
        ] as $old) {
            if (str_contains($stored, $old)) {
                return true;
            }
        }

        // Старая фраза, из‑за которой путали модель — принудительно обновить дефолт.
        if (str_contains($stored, 'русский перевод сделает админка')) {
            return true;
        }

        return false;
    }

    public static function defaultPrompt(): string
    {
        return self::defaultPromptRu();
    }

    /**
     * В Gemini — без служебной метки LANGUAGE_* (она только для версии в БД/админке).
     */
    public static function promptForGemini(string $prompt): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $prompt) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*LANGUAGE_[A-Z0-9_]+\s*$/', $line)) {
                continue;
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    /** @deprecated alias → русский рабочий промпт */
    public static function defaultPromptHe(): string
    {
        return self::defaultPromptRu();
    }

    /**
     * Единственный рабочий промпт: полный мастер на русском + ответ values на иврите.
     * Показывается в админке и уходит в Gemini.
     */
    public static function defaultPromptRu(): string
    {
        return <<<'PROMPT'
LANGUAGE_RU_INSTR_HE_OUT_V7

Мастер-Промпт Обратного Инжиниринга: Алгоритмизация Авторской Логики

ЯЗЫК:
Инструкция — на русском. Все строковые values в JSON — только на иврите (עברית בלבד).
Ключи JSON — латиница. В values нет кириллицы и нет английских предложений.
places_text в fine_tune — как во входе (SOURCE). Числа и true/false — как указано.

Ты — ведущий системный архитектор туристических данных и эксперт по обратному инжинирингу авторских маршрутов.

ЦЕЛЬ ТВОЕЙ РАБОТЫ:
Проведи глубокий логистический и смысловой обратный инжиниринг предоставленного готового маршрута автора. Извлеки скрытый ход мыслей (Chain of Thought), строгие авторские правила отбора и архетипы решений, на которых этот маршрут был построен.
Результат разбора напрямую поступает в обучающий датасет (Fine-Tuning) и реляционную базу данных, чтобы дообученная модель мыслила в точности как автор-эксперт.

ЖЁСТКИЕ ПРАВИЛА АНАЛИЗА (Author's Operating DNA):
1. Прагматизм и ноль литературной «воды»: объясняй решения через время в пути, физические силы, световой день и доступность инфраструктуры. Никаких «эмоциональных градиентов» и «философии дорог».
2. Безоговорочный приоритет Вау-фактора: автор всегда предпочитает всемирно известные, ошеломляющие локации (Сирмионе, Брайес) серым и тихим аналогам (Пескьера, Доббьяко), даже если клиент просит «без людей».
3. Формула дня: светлое время суток — 1–2 природных якоря или активности (озеро, подъёмник, водопад, рафтинг); вечер — атмосферный городок для променада и ужина. Городок может повторяться два вечера подряд, если база расположена рядом.
4. Скоростная логистика: между регионами движение идёт строго по быстрым автострадам (Autostrada), без искусственных панорамных объездов. Съезды в сторону (30–60 мин) допустимы только ради супер-доминант (expert_score >= 90).
5. Календарная защита: детские парки (Гардаленд и др.) и точки массового скопления никогда не ставятся на субботу и воскресенье.
6. Парковки и ZTL: высокий сезон и риск штрафов ZTL не повод отказываться от жемчужин; город всегда комплектуется точной парковкой вне зоны камер.
7. Авторский сюрприз (Wildcard): автор часто внедряет в стандартный набор 1–2 неочевидные точки (панорамная терраса, тайное ущелье), превосходящие ожидания туриста.
8. Плотность без жалости: отсутствие пустых пауз; рабочий световой день длится 6–8 часов активного времени, а чистое вождение жёстко ограничено потолком в 4 часа в сутки (плечо радиального выезда — не более 2 часов в одну сторону).

Вход после --- SOURCE ---: JSON (title, route_text, days). Ответ — строго JSON по схеме ниже.
В схеме ниже подсказки полей на русском; заполняй values на иврите.

{
  "trip_archetype": {
    "archetype_name": "название архетипа на иврите (напр. семья+Гарда / динамичная пара+Доломиты / соблюдающие)",
    "target_profile": {
      "group_composition": "состав группы — на иврите",
      "pace_and_physical_load": "уровень активности — на иврите",
      "cultural_bias": "культурный фильтр (по умолчанию 0 церквей/музеев) — на иврите",
      "special_constraints": ["ограничения на иврите: шаббат, кашрут…"]
    },
    "potential_client_queries": [
      "короткий эмоциональный запрос — на иврите",
      "прагматичный с болями — на иврите",
      "развёрнутый с рамками — на иврите"
    ]
  },
  "macro_architecture": {
    "topology": "Hub-and-Spoke или Linear Road-Trip",
    "base_camps_logic": "почему базы и шаг смены — на иврите",
    "highway_transit_strategy": "длинные переезды / Autostrada — на иврите",
    "arrival_fatigue_management": "День1 ≥3ч? как День2 компенсирует — на иврите"
  },
  "micro_clustering": [
    {
      "day_number": 1,
      "day_title": "краткое название дня — на иврите",
      "day_formula_check": "утро природа + день активность + вечер город — на иврите",
      "weekend_shield_applied": false,
      "signature_wildcard": "авторский сюрприз или пусто — на иврите",
      "locations_selection_logic": [
        {
          "location_name": "название точки (как в SOURCE)",
          "selection_reason": "почему эта точка — на иврите",
          "allocated_time_slot": "2–4 часа или фото-стоп 20–40 мин"
        }
      ],
      "logistics_and_safety": {
        "estimated_driving_time_min": 180,
        "ztl_and_parking_solution": "парковка вне ZTL — на иврите"
      }
    }
  ],
  "fine_tune": {
    "question": "короткий уникальный запрос клиента — на иврите",
    "answer": {
      "summary": "для кого + регионы + базы — на иврите",
      "days": [
        {
          "day_index": 1,
          "title": "роль дня — на иврите",
          "places_text": "места из SOURCE"
        }
      ]
    }
  }
}

Обязательно: все дни входа в fine_tune.answer.days. Values ответа — только иврит (без кириллицы).
PROMPT;
    }

    /** Сохраняет единственный рабочий (русский) промпт. $uiLang игнорируется. */
    public static function savePromptForUi(string $uiLang, string $prompt): void
    {
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, trim($prompt));
    }

    /** Сброс к дефолту (русский). $uiLang игнорируется. */
    public static function resetPromptForUi(string $uiLang): string
    {
        $fresh = self::defaultPromptRu();
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    /**
     * @param  'gemini'|'claude'  $provider
     * @return array{principle: array<string, mixed>, provider: string}
     */
    public function extract(FtShortRoute $route, string $provider = 'gemini'): array
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }

        $days = is_array($route->days) ? $route->days : [];
        if ($days === [] && trim((string) ($route->route_text ?? '')) === '') {
            $data = [
                'skipped' => true,
                'reason' => 'no_days',
                'schema_version' => self::SCHEMA_VERSION,
                'extracted_at' => now()->toIso8601String(),
                're_provider' => $provider,
            ];
            $this->storePrinciple($route, $provider, $data, clearRu: true);
            $route->save();

            return ['principle' => $data, 'provider' => $provider];
        }

        $daysPayload = [];
        foreach ($days as $d) {
            if (! is_array($d)) {
                continue;
            }
            $idx = (int) ($d['day_index'] ?? (count($daysPayload) + 1));
            $places = trim((string) ($d['places'] ?? ''));
            $opts = array_values(array_filter(array_map(
                static fn ($o) => trim((string) $o),
                (array) ($d['options'] ?? [])
            )));
            $daysPayload[] = [
                'day_index' => $idx,
                'places' => $places,
                'options' => $opts,
            ];
        }

        $material = json_encode([
            'ft_short_route_id' => $route->id,
            'doc_code' => $route->doc_code,
            'title' => $route->displayTitle(false),
            'days_count' => $route->days_count ?: count($daysPayload),
            'route_text' => (string) ($route->route_text ?? ''),
            'days' => $daysPayload,
            'output_language' => 'he',
            'note' => 'Все строковые values JSON — иврит. Места как в SOURCE. Без кириллицы в values.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $material = mb_substr((string) $material, 0, 90000);

        $instruction = self::promptForGemini(self::resolvePromptRu() ?: self::defaultPromptRu());

        $raw = $this->askProvider($provider, $instruction, $material);
        $data = $this->normalize($raw, $daysPayload, $this->shortQuestionFallback($route));

        // Если модель всё же ответила по-русски — один жёсткий retry (values снова на иврите).
        if ($this->looksLikeRussian($data) && ! $this->looksLikeHebrew($data)) {
            $retryInstruction = $instruction."\n\nПовтор: предыдущий ответ был на русском. Перепиши весь JSON: все string values только на иврите, без кириллицы.";
            $raw = $this->askProvider($provider, $retryInstruction, $material);
            $data = $this->normalize($raw, $daysPayload, $this->shortQuestionFallback($route));
        }

        if ($this->looksLikeRussian($data) && ! $this->looksLikeHebrew($data)) {
            throw new \RuntimeException(
                'Модель вернула разбор на русском вместо иврита. Нажми «Получить разбор» ещё раз.'
            );
        }

        $data['schema_version'] = self::SCHEMA_VERSION;
        $data['extracted_at'] = now()->toIso8601String();
        $data['output_language'] = 'he';
        $data['re_provider'] = $provider;

        // Gemini и Claude — разные слоты: Claude не затирает Gemini и наоборот.
        if ($provider === 'claude') {
            $this->storePrinciple($route, 'claude', $data, clearRu: true);
        } else {
            // Новый Gemini HE: сбрасываем RU, кроме misplaced русского из HE-слота.
            $oldRu = is_array($route->reverse_principle_ru) ? $route->reverse_principle_ru : null;
            $keepMisplacedRu = is_array($oldRu)
                && ! empty($oldRu['misplaced_russian_from_he_slot'])
                && empty($oldRu['skipped']);
            $this->storePrinciple($route, 'gemini', $data, clearRu: ! $keepMisplacedRu);
        }
        $route->save();

        return ['principle' => $data, 'provider' => $provider];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePrinciple(FtShortRoute $route, string $provider, array $data, bool $clearRu): void
    {
        if ($provider === 'claude') {
            $route->reverse_principle_claude = $data;
            if ($clearRu) {
                $route->reverse_principle_claude_ru = null;
                $meta = is_array($route->meta) ? $route->meta : [];
                unset($meta['claude_ru_units']);
                $route->meta = $meta === [] ? null : $meta;
            }

            return;
        }

        $route->reverse_principle = $data;
        if ($clearRu) {
            $route->reverse_principle_ru = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function askProvider(string $provider, string $instruction, string $material): array
    {
        if ($provider === 'claude') {
            return $this->claude->ask($instruction, $material);
        }

        // gemini-3.8 rejects thinkingLevel MINIMAL — LOW is the floor.
        return $this->gemini->ask($instruction, $material, 'low');
    }

    /** Старые RE ошибочно на русском в поле HE — отдать как RU-кандидат. */
    public static function sampleText(array $rp): string
    {
        $arch = is_array($rp['trip_archetype'] ?? null) ? $rp['trip_archetype'] : [];
        $tp = is_array($arch['target_profile'] ?? null) ? $arch['target_profile'] : [];
        $parts = [
            $arch['archetype_name'] ?? null,
            $tp['group_composition'] ?? null,
            $rp['target_audience']['group_profile'] ?? null,
            $rp['audience_portrait']['group_composition'] ?? null,
            $rp['target_audience']['why_route_fits_audience'] ?? null,
            $rp['macro_architecture']['base_camps_logic'] ?? null,
            $rp['macro_architecture']['region_selection_logic'] ?? null,
            $rp['principle'] ?? null,
        ];

        return trim(implode(' ', array_map(static fn ($v) => is_string($v) ? $v : '', $parts)));
    }

    public static function isCyrillicHeavy(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        $heb = preg_match_all('/\p{Hebrew}/u', $text) ?: 0;

        return $cyr >= 20 && $cyr > $heb * 2;
    }

    public static function isHebrewHeavy(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $heb = preg_match_all('/\p{Hebrew}/u', $text) ?: 0;
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;

        return $heb >= 20 && $heb > $cyr;
    }

    /** @param  array<string, mixed>  $data */
    private function looksLikeRussian(array $data): bool
    {
        return self::isCyrillicHeavy(self::sampleText($data));
    }

    /** @param  array<string, mixed>  $data */
    private function looksLikeHebrew(array $data): bool
    {
        return self::isHebrewHeavy(self::sampleText($data));
    }

    private function shortQuestionFallback(FtShortRoute $route): string
    {
        $title = trim((string) ($route->displayTitle(false) ?: ''));
        $n = (int) ($route->days_count ?: 0);
        $hubs = $title !== '' ? $title : 'איטליה';
        if ($n > 0) {
            return $hubs.', '.$n.' ימים';
        }

        return $hubs;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array{day_index:int,places:string,options:list<string>}>  $daysPayload
     * @return array<string, mixed>
     */
    private function normalize(array $raw, array $daysPayload, string $shortQuestionFallback = ''): array
    {
        $arch = is_array($raw['trip_archetype'] ?? null) ? $raw['trip_archetype'] : [];
        $tp = is_array($arch['target_profile'] ?? null) ? $arch['target_profile'] : [];
        $ta = is_array($raw['target_audience'] ?? null) ? $raw['target_audience'] : [];
        $audienceOld = is_array($raw['audience_portrait'] ?? null) ? $raw['audience_portrait'] : [];
        $fitOld = is_array($raw['audience_fit'] ?? null) ? $raw['audience_fit'] : [];

        $archetypeName = $this->str($arch['archetype_name'] ?? null, 400);
        $groupProfile = $this->str(
            $tp['group_composition']
                ?? $ta['group_profile']
                ?? $audienceOld['group_composition']
                ?? null,
            1200
        );
        $activity = $this->str(
            $tp['pace_and_physical_load']
                ?? $ta['activity_and_pace_level']
                ?? $audienceOld['activity_and_comfort']
                ?? null,
            1200
        );
        $culturalBias = $this->str($tp['cultural_bias'] ?? null, 800);

        $constraints = [];
        foreach ((array) ($tp['special_constraints'] ?? $ta['special_constraints'] ?? []) as $c) {
            $c = $this->str($c, 400);
            if ($c !== null) {
                $constraints[] = $c;
            }
        }
        if ($constraints === []) {
            $one = $this->str($audienceOld['constraints'] ?? null, 800);
            if ($one !== null) {
                $constraints[] = $one;
            }
        }

        $whyFits = $this->str(
            $ta['why_route_fits_audience'] ?? $fitOld['why_this_route_fits'] ?? $archetypeName,
            2500
        );

        $queries = [];
        foreach ((array) ($arch['potential_client_queries'] ?? $ta['potential_user_queries'] ?? $fitOld['possible_queries'] ?? []) as $q) {
            $q = $this->str($q, 500);
            if ($q !== null) {
                $queries[] = $q;
            }
            if (count($queries) >= 5) {
                break;
            }
        }

        $macro = is_array($raw['macro_architecture'] ?? null) ? $raw['macro_architecture'] : [];
        $topology = $this->str($macro['topology'] ?? null, 200);
        $bases = $this->str(
            $macro['base_camps_logic']
                ?? $macro['base_camps_strategy']
                ?? $macro['routing_strategy']
                ?? $raw['geo_logic']
                ?? null,
            2000
        );
        $highway = $this->str($macro['highway_transit_strategy'] ?? null, 2000);
        $fatigue = $this->str($macro['arrival_fatigue_management'] ?? null, 2000);
        $regionLogic = $this->str(
            $macro['region_selection_logic'] ?? $highway ?? $raw['principle'] ?? $archetypeName,
            2000
        );
        $transit = $this->str($macro['transit_days_handling'] ?? $highway, 2000);

        $microList = [];
        foreach ((array) ($raw['micro_clustering'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $locs = [];
            foreach ((array) ($row['locations_selection_logic'] ?? []) as $loc) {
                if (! is_array($loc)) {
                    continue;
                }
                $locs[] = [
                    'location_name' => $this->str($loc['location_name'] ?? null, 200),
                    'selection_reason' => $this->str($loc['selection_reason'] ?? null, 800),
                    'allocated_time_slot' => $this->str($loc['allocated_time_slot'] ?? null, 200),
                ];
            }
            $logistics = is_array($row['logistics_and_safety'] ?? null) ? $row['logistics_and_safety'] : [];
            $driveMin = (int) ($logistics['estimated_driving_time_min'] ?? 0);
            $dayTitle = $this->str($row['day_title'] ?? $row['day_description'] ?? null, 400);
            $formula = $this->str($row['day_formula_check'] ?? $row['audience_adapted_logic'] ?? $row['practical_logic'] ?? null, 1500);
            $wildcard = $this->str($row['signature_wildcard'] ?? null, 800);
            $ztl = $this->str(
                $logistics['ztl_and_parking_solution']
                    ?? $row['logistics_and_timing']
                    ?? $row['logistics_and_fatigue']
                    ?? null,
                800
            );
            $weekend = $row['weekend_shield_applied'] ?? null;
            if (is_string($weekend)) {
                $weekend = in_array(mb_strtolower(trim($weekend)), ['1', 'true', 'yes', 'да'], true);
            } else {
                $weekend = (bool) $weekend;
            }

            $microList[] = [
                'day_number' => (int) ($row['day_number'] ?? 0),
                'day_title' => $dayTitle,
                'day_description' => $dayTitle,
                'day_formula_check' => $formula,
                'weekend_shield_applied' => $weekend,
                'signature_wildcard' => $wildcard,
                'locations_selection_logic' => $locs,
                'logistics_and_safety' => [
                    'estimated_driving_time_min' => $driveMin > 0 ? $driveMin : null,
                    'ztl_and_parking_solution' => $ztl,
                ],
                'audience_adapted_logic' => $formula,
                'logistics_and_timing' => $ztl,
                'practical_logic' => $formula,
                'logistics_and_fatigue' => $ztl,
            ];
        }

        $abstract = $this->str(
            $raw['abstract_user_prompt'] ?? $queries[1] ?? $queries[0] ?? null,
            2000
        );

        $ft = is_array($raw['fine_tune'] ?? null) ? $raw['fine_tune'] : [];
        $ftQuestion = $this->str($ft['question'] ?? null, 400);
        if ($ftQuestion === null || $ftQuestion === '') {
            $ftQuestion = $this->str($shortQuestionFallback !== '' ? $shortQuestionFallback : ($queries[0] ?? $abstract), 400);
        }
        $ftAnswerRaw = is_array($ft['answer'] ?? null) ? $ft['answer'] : [];
        $ftSummary = $this->str(
            $ftAnswerRaw['summary'] ?? trim(($archetypeName ?? '').' '.($bases ?? '')),
            2000
        );

        $ftDays = [];
        foreach ((array) ($ftAnswerRaw['days'] ?? []) as $fd) {
            if (! is_array($fd)) {
                continue;
            }
            $ftDays[] = [
                'day_index' => (int) ($fd['day_index'] ?? count($ftDays) + 1),
                'title' => $this->str($fd['title'] ?? null, 200),
                'places_text' => $this->str($fd['places_text'] ?? $fd['places'] ?? null, 500),
            ];
        }
        if ($ftDays === []) {
            foreach ($daysPayload as $d) {
                $chunk = trim((string) ($d['places'] ?? ''));
                foreach ((array) ($d['options'] ?? []) as $o) {
                    $o = trim((string) $o);
                    if ($o !== '') {
                        $chunk = $chunk === '' ? $o : ($chunk.' / '.$o);
                    }
                }
                $ftDays[] = [
                    'day_index' => (int) ($d['day_index'] ?? count($ftDays) + 1),
                    'title' => null,
                    'places_text' => $chunk !== '' ? $chunk : null,
                ];
            }
        }

        return [
            'trip_archetype' => [
                'archetype_name' => $archetypeName,
                'target_profile' => [
                    'group_composition' => $groupProfile,
                    'pace_and_physical_load' => $activity,
                    'cultural_bias' => $culturalBias,
                    'special_constraints' => $constraints,
                ],
                'potential_client_queries' => $queries,
            ],
            'target_audience' => [
                'group_profile' => $groupProfile,
                'activity_and_pace_level' => $activity,
                'special_constraints' => $constraints,
                'why_route_fits_audience' => $whyFits,
                'potential_user_queries' => $queries,
            ],
            'audience_portrait' => [
                'group_composition' => $groupProfile,
                'activity_and_comfort' => $activity,
                'constraints' => $constraints !== [] ? implode('; ', $constraints) : null,
            ],
            'audience_fit' => [
                'why_this_route_fits' => $whyFits,
                'possible_queries' => $queries,
            ],
            'abstract_user_prompt' => $abstract,
            'synthetic_query' => $abstract,
            'macro_architecture' => [
                'topology' => $topology,
                'base_camps_logic' => $bases,
                'base_camps_strategy' => $bases,
                'highway_transit_strategy' => $highway,
                'arrival_fatigue_management' => $fatigue,
                'region_selection_logic' => $regionLogic,
                'transit_days_handling' => $transit,
                'routing_strategy' => trim(($bases ?? '').($highway ? ' '.$highway : '')),
            ],
            'micro_clustering' => $microList,
            'fine_tune' => [
                'question' => $ftQuestion,
                'answer' => [
                    'summary' => $ftSummary,
                    'days' => $ftDays,
                ],
            ],
            'principle' => $regionLogic,
            'geo_logic' => $bases,
            'confidence' => in_array($raw['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? $raw['confidence'] : 'medium',
        ];
    }

    private function str(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
