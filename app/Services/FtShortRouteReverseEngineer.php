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

    /** Промпт для ручной работы в чате Gemini (копируешь → вставляешь JSON обратно). */
    public const MANUAL_PROMPT_SETTING_KEY_RU = 'travel.reverse_engineer.ft_short_manual_prompt_ru_v1';

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

        // Ручное «Сохранить» — свято. Не переписываем и не «улучшаем» сохранённый текст.
        if ($stored !== ''
            && str_contains($stored, 'trip_archetype')
            && str_contains($stored, 'signature_wildcard')
            && str_contains($stored, 'fine_tune')
        ) {
            return $stored;
        }

        $fresh = self::defaultPromptRu();
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    /**
     * Если в сохранённом промпте нет инструкции про полный Word — вставить культурный блок SOURCE.
     */
    public static function ensureFullDocumentSourceBlock(string $prompt): string
    {
        if (str_contains($prompt, 'full_word_body')) {
            // Убрать разговорное «простыня», если осталось в старых дефолтах.
            if (str_contains($prompt, 'простыню') || str_contains($prompt, 'простыня')) {
                $prompt = str_replace(
                    [
                        'Не игнорируй простыню.',
                        'Не игнорируй простыню',
                        'простыню',
                        'простыня',
                    ],
                    [
                        'Не ограничивайся одним каркасом, если передан полный текст документа.',
                        'Не ограничивайся одним каркасом, если передан полный текст документа',
                        'полный текст документа',
                        'полный текст документа',
                    ],
                    $prompt
                );
            }

            return $prompt;
        }

        $block = self::fullDocumentSourceBlock();

        // Вставить перед «Ответ — строго JSON», если есть; иначе перед схемой / в конец.
        if (preg_match('/Ответ\s*—\s*строго JSON/u', $prompt)) {
            return preg_replace(
                '/Ответ\s*—\s*строго JSON/u',
                $block."\nОтвет — строго JSON",
                $prompt,
                1
            ) ?? ($prompt."\n\n".$block);
        }

        if (str_contains($prompt, '{')) {
            $pos = strpos($prompt, '{');

            return rtrim(substr($prompt, 0, $pos))."\n\n".$block."\n\n".ltrim(substr($prompt, $pos));
        }

        return rtrim($prompt)."\n\n".$block;
    }

    public static function fullDocumentSourceBlock(): string
    {
        return <<<'BLOCK'
Вход после --- SOURCE ---: JSON с двумя слоями исходника автора:
- route_as_is — «Маршрут (как есть)» из карточки (текст маршрута целиком);
- full_word_body — «Текст Word (простыня)» — полный текст Word-документа.
Разбирай оба слоя. days / outline / каркас заголовков дней в SOURCE не используем и не ждём.
BLOCK;
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
     * Единственный рабочий промпт: директорский текст (без правок агента).
     * Файл: resources/prompts/director_re_prompt_ru.txt
     */
    public static function defaultPromptRu(): string
    {
        $path = resource_path('prompts/director_re_prompt_ru.txt');
        if (is_file($path)) {
            $text = trim((string) file_get_contents($path));
            if ($text !== '') {
                return $text;
            }
        }

        return 'Мастер-Промпт Обратного Инжиниринга (файл resources/prompts/director_re_prompt_ru.txt не найден).';
    }

    /** Сохраняет единственный рабочий (русский) промпт. $uiLang игнорируется. Текст — как прислали, без правок. */
    public static function savePromptForUi(string $uiLang, string $prompt): string
    {
        $prompt = trim($prompt);
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $prompt);

        return (string) AppSetting::getValue(self::PROMPT_SETTING_KEY_RU, $prompt);
    }

    /** Сброс к дефолту (русский). $uiLang игнорируется. */
    public static function resetPromptForUi(string $uiLang): string
    {
        $fresh = self::defaultPromptRu();
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    public static function defaultManualPromptRu(): string
    {
        $path = resource_path('prompts/director_re_prompt_manual_ru.txt');
        if (is_file($path)) {
            $text = trim((string) file_get_contents($path));
            if ($text !== '') {
                return $text;
            }
        }

        return "ПРОМПТ ДЛЯ РУЧНОЙ РАБОТЫ В GEMINI\n\n".self::defaultPromptRu();
    }

    public static function resolveManualPromptRu(): string
    {
        $stored = trim((string) AppSetting::getValue(self::MANUAL_PROMPT_SETTING_KEY_RU, ''));
        if ($stored !== '') {
            return $stored;
        }
        $fresh = self::defaultManualPromptRu();
        AppSetting::putValue(self::MANUAL_PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    public static function saveManualPromptRu(string $prompt): string
    {
        $prompt = trim($prompt);
        AppSetting::putValue(self::MANUAL_PROMPT_SETTING_KEY_RU, $prompt);

        return (string) AppSetting::getValue(self::MANUAL_PROMPT_SETTING_KEY_RU, $prompt);
    }

    public static function resetManualPromptRu(): string
    {
        $fresh = self::defaultManualPromptRu();
        AppSetting::putValue(self::MANUAL_PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    /**
     * Ручная сборка: JSON из чата Gemini → те же поля reverse_principle.
     *
     * @return array{principle: array<string, mixed>, provider: string}
     */
    public function importManualJson(FtShortRoute $route, string $rawJson): array
    {
        $rawJson = trim($rawJson);
        $rawJson = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $rawJson) ?? $rawJson;
        $decoded = json_decode(trim($rawJson), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Не JSON: вставь ответ Gemini целиком (объект с trip_archetype / micro_clustering / fine_tune).');
        }

        $data = $this->normalize($decoded, [], $this->shortQuestionFallback($route));
        if (self::isHollowPrinciple($data)) {
            throw new \RuntimeException('JSON пустой (нет архетипа и дней). В документ не записано.');
        }

        $data['schema_version'] = self::SCHEMA_VERSION;
        $data['extracted_at'] = now()->toIso8601String();
        $data['output_language'] = 'he';
        $data['re_provider'] = 'gemini';
        $data['source'] = 'ft_short_manual_paste';

        $this->storePrinciple($route, 'gemini', $data, clearRu: true);
        $route->save();

        $route->loadMissing('newShortRoute');
        $nsr = $route->newShortRoute;
        if ($nsr) {
            $nsr->reverse_principle = $data;
            $nsr->reverse_principle_ru = null;
            $nsr->save();
        }

        return ['principle' => $data, 'provider' => 'gemini'];
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

        // SOURCE из New Short: «Маршрут (как есть)» + «Текст Word (простыня)».
        // Старый days-каркас / FT route_text в Gemini не отправляем.
        $route->loadMissing('newShortRoute');
        $nsr = $route->newShortRoute;
        $routeAsIs = trim((string) ($nsr?->route_text ?? ''));
        $bodyFull = trim((string) ($nsr?->body_text ?? ''));
        if ($routeAsIs === '' && $bodyFull === '') {
            // Сбой / нет SOURCE — в документ ничего не пишем.
            throw new \RuntimeException(
                'Нет SOURCE: пусты «Маршрут (как есть)» и «Текст Word (простыня)» у связанного New Short. В документ не записано.'
            );
        }

        $maxBody = 180_000;
        $bodyTruncated = false;
        $body = $bodyFull;
        if ($body !== '' && mb_strlen($body) > $maxBody) {
            $body = mb_substr($body, 0, $maxBody)."\n…[truncated]";
            $bodyTruncated = true;
        }

        $material = json_encode([
            'ft_short_route_id' => $route->id,
            'new_short_route_id' => $nsr?->id,
            'doc_code' => $route->doc_code,
            'title' => $route->displayTitle(false),
            'route_as_is' => $routeAsIs,
            'full_word_body' => $body,
            'full_word_body_truncated' => $bodyTruncated,
            'output_language' => 'he',
            'note' => 'SOURCE = route_as_is (Маршрут как есть) + full_word_body (простыня Word). days/каркас не передаём.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $data = $this->analyzeMaterial(
            $provider,
            (string) $material,
            [],
            $this->shortQuestionFallback($route)
        );

        // Ещё раз: пустой/битый ответ в документ не пишем (analyzeMaterial уже бросает, это страховка).
        if (self::isHollowPrinciple($data)) {
            throw new \RuntimeException(
                'Модель вернула пустой разбор (без архетипа и дней). В документ не записано.'
            );
        }

        $data['schema_version'] = self::SCHEMA_VERSION;
        $data['extracted_at'] = now()->toIso8601String();
        $data['output_language'] = 'he';
        $data['re_provider'] = $provider;
        $data['source'] = 'ft_short_route_as_is_and_word';
        $data['source_chars'] = [
            'route_as_is' => mb_strlen($routeAsIs),
            'full_word_body' => mb_strlen($bodyFull),
            'full_word_body_sent' => mb_strlen($body),
            'truncated' => $bodyTruncated,
        ];

        // Gemini и Claude — разные слоты: Claude не затирает Gemini и наоборот.
        if ($provider === 'claude') {
            $this->storePrinciple($route, $provider, $data, clearRu: true);
        } else {
            // Новый Gemini HE: сбрасываем RU, кроме misplaced русского из HE-слота.
            $oldRu = is_array($route->reverse_principle_ru) ? $route->reverse_principle_ru : null;
            $keepMisplacedRu = is_array($oldRu)
                && ! empty($oldRu['misplaced_russian_from_he_slot'])
                && empty($oldRu['skipped']);
            $this->storePrinciple($route, 'gemini', $data, clearRu: ! $keepMisplacedRu);
        }
        $route->save();

        // Зеркало в New Short (Gemini), чтобы результат был и там.
        if ($nsr && $provider === 'gemini') {
            $nsr->reverse_principle = $data;
            $nsr->reverse_principle_ru = null;
            $nsr->save();
        }

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
     * Общий прогон RE по произвольному SOURCE-материалу (FT Short / New Short).
     *
     * @param  list<array{day_index:int,places:string,options:list<string>}>  $daysPayload
     * @return array<string, mixed>
     */
    public function analyzeMaterial(
        string $provider,
        string $material,
        array $daysPayload = [],
        string $shortQuestionFallback = '',
    ): array {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }

        $instruction = self::promptForGemini(self::resolvePromptRu() ?: self::defaultPromptRu());
        $raw = $this->askProvider($provider, $instruction, $material, 'low');
        $data = $this->normalize($raw, $daysPayload, $shortQuestionFallback);

        // Пустой каркас (все поля null) — не сохраняем как успех, один retry жёстче.
        if (self::isHollowPrinciple($data)) {
            $retryInstruction = $instruction."\n\nПовтор: предыдущий JSON был пустым (null/пустые массивы). Заполни схему по SOURCE полностью: trip_archetype, macro_architecture, micro_clustering (по дням), fine_tune. Values — только иврит.";
            $raw = $this->askProvider($provider, $retryInstruction, $material, 'medium');
            $data = $this->normalize($raw, $daysPayload, $shortQuestionFallback);
        }

        // Если модель всё же ответила по-русски — один жёсткий retry (values снова на иврите).
        if ($this->looksLikeRussian($data) && ! $this->looksLikeHebrew($data)) {
            $retryInstruction = $instruction."\n\nПовтор: предыдущий ответ был на русском. Перепиши весь JSON: все string values только на иврите, без кириллицы.";
            $raw = $this->askProvider($provider, $retryInstruction, $material, 'medium');
            $data = $this->normalize($raw, $daysPayload, $shortQuestionFallback);
        }

        if (self::isHollowPrinciple($data)) {
            throw new \RuntimeException(
                'Модель вернула пустой разбор (без архетипа и дней). В документ не записано. Нажми Gemini ещё раз.'
            );
        }

        if ($this->looksLikeRussian($data) && ! $this->looksLikeHebrew($data)) {
            throw new \RuntimeException(
                'Модель вернула разбор на русском вместо иврита. Нажми «Получить разбор» ещё раз.'
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function isHollowPrinciple(array $data): bool
    {
        if (! empty($data['skipped'])) {
            return true;
        }

        $arch = is_array($data['trip_archetype'] ?? null) ? $data['trip_archetype'] : [];
        $name = trim((string) ($arch['archetype_name'] ?? ''));
        $micro = is_array($data['micro_clustering'] ?? null) ? $data['micro_clustering'] : [];
        $sample = trim(self::sampleText($data));

        return $name === '' && $micro === [] && $sample === '';
    }

    /**
     * @return array<string, mixed>
     */
    private function askProvider(string $provider, string $instruction, string $material, string $thinking = 'low'): array
    {
        if ($provider === 'claude') {
            return $this->claude->ask($instruction, $material);
        }

        // gemini-3.8 rejects thinkingLevel MINIMAL — LOW is the floor.
        return $this->gemini->ask($instruction, $material, $thinking);
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
