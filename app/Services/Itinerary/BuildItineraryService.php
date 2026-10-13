<?php

namespace App\Services\Itinerary;

use App\Services\GeminiTranslator;
use RuntimeException;

class BuildItineraryService
{
    public function __construct(
        private StructuredTourRetriever $retriever,
        private GeminiItineraryFormatter $formatter,
        private QuestionnaireBrief $briefParser,
        private CuratedDayPoolBuilder $curator,
        private GeminiTranslator $translator,
        private SearchPlanBuilder $searchPlan,
        private PlaceGeocoder $geocoder,
    ) {}

    /**
     * @return array{
     *   reply: string,
     *   tours: list<array{product_id: int, model: string|null, title: string|null, score: float, meta_lang: string, block_count: int, estimated_days: int}>,
     *   product_id: int|null,
     *   model: string|null,
     *   meta_lang: string|null,
     *   estimated_days: int|null,
     *   pool_days: int,
     *   constraints: array<string, mixed>,
     *   search_ms: int,
     *   format_ms: int,
     *   total_ms: int
     * }
     */
    /**
     * Stage 1: find tours and return readable text previews (no Gemini assemble).
     *
     * @return array{
     *   sources: list<array{
     *     product_id: int,
     *     model: string|null,
     *     title: string|null,
     *     score: float,
     *     estimated_days: int,
     *     meta_lang: string,
     *     block_count: int,
     *     preview_text: string
     *   }>,
     *   pool_days: int,
     *   constraints: array<string, mixed>,
     *   search_ms: int,
     *   total_ms: int
     * }
     */
    /**
     * Interpret questionnaire → what we will search in the vector DB (no search yet).
     *
     * @return array<string, mixed>
     */
    public function planSearch(string $question): array
    {
        $plan = $this->searchPlan->build($question);
        $plan['question'] = trim($question);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function refineSearchPlan(array $plan, string $followUp): array
    {
        return $this->searchPlan->refine($plan, $followUp);
    }

    /**
     * @param  array<string, mixed>|null  $plan
     */
    public function previewSources(
        string $question,
        int $tourLimit = 5,
        ?string $vectorQuery = null,
        ?array $plan = null,
    ): array {
        $t0 = microtime(true);
        $locations = is_array($plan['locations'] ?? null) ? $plan['locations'] : [];
        $constraints = is_array($plan['constraints'] ?? null)
            ? $plan['constraints']
            : $this->briefParser->parse($question);

        if ($locations !== []) {
            $searchMsT0 = microtime(true);
            $tours = $this->retriever->findToursByLocations($locations, $constraints, null);
            $searchMs = (int) round((microtime(true) - $searchMsT0) * 1000);
            if ($tours === []) {
                // Fallback to semantic pool if mention scan found nothing
                [$tours, $constraints, $searchMs] = $this->resolveTourPool($question, $tourLimit, $vectorQuery);
            }
        } else {
            [$tours, $constraints, $searchMs] = $this->resolveTourPool($question, $tourLimit, $vectorQuery);
        }

        $poolDays = 0;
        $sources = [];
        $locationMode = $locations !== [];
        foreach ($tours as $tour) {
            $poolDays += max(1, (int) ($tour['estimated_days'] ?? 1));
            $blocks = $tour['meta']['blocks'] ?? [];
            if (isset($tour['preview_text']) && is_string($tour['preview_text']) && $tour['preview_text'] !== '') {
                $preview = $tour['preview_text'];
            } elseif ($locationMode || ! empty($tour['preview_deferred'])) {
                $preview = '';
            } else {
                $preview = $this->buildPreviewText($tour['meta'] ?? [], 12000);
            }
            $routeLines = is_array($tour['route_lines'] ?? null) ? $tour['route_lines'] : [];
            $sources[] = [
                'product_id' => $tour['product_id'],
                'model' => $tour['model'],
                'title' => $tour['title'],
                'score' => $tour['score'],
                'estimated_days' => $tour['estimated_days'],
                'meta_lang' => $tour['meta_lang'] ?? 'he',
                'block_count' => (int) ($tour['block_count'] ?? (is_array($blocks) ? count($blocks) : 0)),
                'matched_locations' => $tour['matched_locations'] ?? [],
                'route_lines' => $routeLines,
                'route_line_count' => (int) ($tour['route_line_count'] ?? count($routeLines)),
                'match_note' => $tour['match_note'] ?? null,
                'preview_text' => $preview,
                'preview_deferred' => $preview === '' && ($locationMode || ! empty($tour['preview_deferred'])),
            ];
        }

        return [
            'sources' => $sources,
            'pool_days' => $poolDays,
            'constraints' => $constraints,
            'search_ms' => $searchMs,
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public function hydrateSourcesForProcessing(array $sources, string $preferLang = 'ru'): array
    {
        $needIds = [];
        foreach ($sources as $i => $src) {
            if (! is_array($src)) {
                continue;
            }
            // Route-line hits already carry itinerary snippets — do not reload full Word meta.
            $routeLines = $src['route_lines'] ?? null;
            if (is_array($routeLines) && $routeLines !== []) {
                if (trim((string) ($src['preview_text'] ?? '')) === '') {
                    $lines = [];
                    foreach ($routeLines as $line) {
                        if (is_string($line) && trim($line) !== '') {
                            $lines[] = trim($line);
                        }
                    }
                    $sources[$i]['preview_text'] = implode("\n", array_slice($lines, 0, 12));
                    $sources[$i]['preview_deferred'] = false;
                }

                continue;
            }
            if (! empty($src['preview_deferred']) || trim((string) ($src['preview_text'] ?? '')) === '') {
                $needIds[] = $i;
            }
        }
        if ($needIds === []) {
            return $sources;
        }

        $subset = [];
        foreach ($needIds as $i) {
            $subset[] = $sources[$i];
        }
        $hydrated = $this->retriever->hydrateSourcePreviews($subset, $preferLang, 10000);
        foreach ($needIds as $k => $i) {
            if (isset($hydrated[$k]) && is_array($hydrated[$k])) {
                $sources[$i] = $hydrated[$k];
            }
        }

        return $sources;
    }

    public function fromUserQuestion(string $question, ?string $systemPrompt = null, int $tourLimit = 5): array
    {
        $t0 = microtime(true);
        [$tours, $constraints, $searchMs] = $this->resolveTourPool($question, $tourLimit);

        return $this->assembleFromTours($question, $tours, $constraints, $systemPrompt, $searchMs, $t0);
    }

    /**
     * Stage 1.5: from preview sources → curated day cards matching the brief.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array{
     *   curated_days: list<array<string, mixed>>,
     *   dropped_days: list<array<string, mixed>>,
     *   brief_note: string,
     *   preferred_regions: list<string>,
     *   constraints: array<string, mixed>,
     *   extracted: int,
     *   kept: int,
     *   curate_ms: int,
     *   total_ms: int
     * }
     */
    public function curateFromSources(string $question, array $sources): array
    {
        $t0 = microtime(true);
        $question = trim($question);
        if ($question === '') {
            throw new RuntimeException('Empty question');
        }
        if ($sources === []) {
            throw new RuntimeException('Нет сырья для отбора');
        }

        $curated = $this->curator->build($question, $sources);
        if (($curated['days'] ?? []) === []) {
            $extracted = (int) ($curated['extracted'] ?? 0);
            throw new RuntimeException(
                'Не удалось извлечь дни из сырья'
                .($extracted > 0
                    ? ' (внутренний сбой: извлечено '.$extracted.', пул пуст)'
                    : ' (не найдены дневные заголовки — попробуйте «Перевести все» и снова «Извлечь дни»)')
            );
        }

        $days = $curated['days'];

        return [
            'curated_days' => $days,
            'curated_text' => $this->curator->formatCuratedText($days),
            'dropped_days' => $curated['dropped'] ?? [],
            'brief_note' => $curated['brief_note'] ?? '',
            'preferred_regions' => $curated['preferred_regions'],
            'constraints' => $curated['constraints'],
            'extracted' => $curated['extracted'],
            'kept' => $curated['kept'],
            'curate_ms' => $curated['ms'],
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseCuratedText(string $text): array
    {
        return $this->curator->parseCuratedText($text);
    }

    /**
     * Stage 1.75: relevant RU catalog of ALL visit days (not an N-day itinerary).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  list<string>  $planLocations
     * @return array{
     *   cleaned_days: list<array<string, mixed>>,
     *   cleaned_text: string,
     *   dropped_days: list<array<string, mixed>>,
     *   clean_note: string,
     *   clean_ms: int,
     *   kept: int,
     *   before: int
     * }
     */
    public function cleanCuratedDays(array $days, array $planLocations = []): array
    {
        if ($days === []) {
            throw new RuntimeException('Нет дней для очистки пула');
        }

        $result = $this->curator->cleanPool($days, $planLocations);
        $geo = $this->geocoder->enrichDays($result['days']);
        $cleaned = $this->curator->groupDaysByRegion($geo['days']);
        $note = trim(($result['note'] ?? '').' '.$geo['note']);

        return [
            'cleaned_days' => $cleaned,
            'cleaned_text' => $this->curator->formatCuratedText($cleaned),
            'dropped_days' => $result['dropped'] ?? [],
            'clean_note' => $note,
            'clean_ms' => (int) ($result['ms'] + $geo['ms']),
            'kept' => count($cleaned),
            'before' => count($days),
            'geo_ms' => $geo['ms'],
            'geo_geocoded' => $geo['geocoded'],
            'geo_missing' => $geo['missing'],
        ];
    }

    /**
     * Translate cleaned day cards to Russian (summary / places / body).
     *
     * @param  list<array<string, mixed>>  $days
     * @return array{
     *   cleaned_days: list<array<string, mixed>>,
     *   cleaned_text: string,
     *   translate_ms: int,
     *   translated_fields: int
     * }
     */
    public function translateDayPool(array $days): array
    {
        $t0 = microtime(true);
        if ($days === []) {
            throw new RuntimeException('Нет дней для перевода');
        }

        $pending = [];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $summary = trim((string) ($day['summary'] ?? ''));
            if ($summary !== '' && $this->needsRuTranslation($summary)) {
                $pending[$i.'.summary'] = $summary;
            }
            $body = trim((string) ($day['body'] ?? ''));
            if ($body !== '' && $this->needsRuTranslation($body)) {
                if (mb_strlen($body) > 2400) {
                    $body = mb_substr($body, 0, 2400).'…';
                }
                $pending[$i.'.body'] = $body;
            }
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            foreach ($places as $j => $place) {
                $place = trim((string) $place);
                if ($place !== '' && $this->needsRuTranslation($place)) {
                    $pending[$i.'.place.'.$j] = $place;
                }
            }
        }

        $map = [];
        if ($pending !== []) {
            foreach (array_chunk($pending, 8, true) as $batch) {
                /** @var array<string, string> $batch */
                $part = $this->translator->translateMap($batch);
                foreach ($batch as $id => $_) {
                    $ru = $part[(string) $id] ?? null;
                    if (is_string($ru) && trim($ru) !== '') {
                        $map[(string) $id] = trim($ru);
                    }
                }
            }
        }

        $out = [];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $summary = trim((string) ($day['summary'] ?? ''));
            if (isset($map[$i.'.summary'])) {
                $summary = $map[$i.'.summary'];
            }
            $body = trim((string) ($day['body'] ?? ''));
            if (isset($map[$i.'.body'])) {
                $body = $map[$i.'.body'];
            }
            $places = [];
            foreach ((is_array($day['places'] ?? null) ? $day['places'] : []) as $j => $place) {
                $place = trim((string) $place);
                $key = $i.'.place.'.$j;
                $places[] = isset($map[$key]) ? $map[$key] : $place;
            }
            $day['summary'] = $summary;
            $day['body'] = $body;
            $day['places'] = $places;
            $out[] = $day;
        }

        $geo = $this->geocoder->enrichDays($out);
        $out = $this->curator->groupDaysByRegion($geo['days']);

        return [
            'cleaned_days' => $out,
            'cleaned_text' => $this->curator->formatCuratedText($out),
            'translate_ms' => (int) round((microtime(true) - $t0) * 1000),
            'translated_fields' => count($map),
        ];
    }

    private function needsRuTranslation(string $text): bool
    {
        // Hebrew letters → translate. Already Cyrillic-only → skip.
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return true;
        }
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            return false;
        }

        // Latin-only place names: leave as-is (Capri, Garda…)
        return false;
    }

    /**
     * Stage 2: stitch itinerary ONLY from curated day cards.
     *
     * @param  list<array<string, mixed>>  $curatedDays
     * @param  list<array<string, mixed>>  $sources
     * @return array{
     *   reply: string,
     *   tours: list<array<string, mixed>>,
     *   curated_days: list<array<string, mixed>>,
     *   product_id: int|null,
     *   model: string|null,
     *   meta_lang: string|null,
     *   estimated_days: int|null,
     *   pool_days: int,
     *   constraints: array<string, mixed>,
     *   search_ms: int,
     *   format_ms: int,
     *   total_ms: int
     * }
     */
    public function assembleFromCuratedDays(
        string $question,
        array $curatedDays,
        array $sources = [],
        ?string $systemPrompt = null,
    ): array {
        $t0 = microtime(true);
        $question = trim($question);
        if ($question === '') {
            throw new RuntimeException('Empty question');
        }
        if ($curatedDays === []) {
            throw new RuntimeException('Сначала отберите общее сырьё (карточки дней)');
        }

        $constraints = $this->briefParser->parse($question);
        $needsGeo = false;
        foreach ($curatedDays as $day) {
            if (! is_array($day)) {
                continue;
            }
            if (! isset($day['lat'], $day['lng']) || $day['lat'] === null || $day['lng'] === null) {
                $needsGeo = true;
                break;
            }
        }
        if ($needsGeo) {
            $curatedDays = $this->geocoder->enrichDays($curatedDays)['days'];
        }
        $prepared = $this->curator->prepareAssemblePool($question, $curatedDays);
        $assembleDays = $prepared['days'];
        if ($assembleDays === []) {
            throw new RuntimeException('После PHP-отбора не осталось карточек для сборки');
        }
        $poolDays = count($assembleDays);

        $models = [];
        $productIds = [];
        foreach ($assembleDays as $day) {
            if (! is_array($day)) {
                continue;
            }
            if (! empty($day['model'])) {
                $models[] = (string) $day['model'];
            }
            if (! empty($day['product_id'])) {
                $productIds[] = (int) $day['product_id'];
            }
        }
        $models = array_values(array_unique($models));
        $productIds = array_values(array_unique($productIds));

        $brief = [
            'question' => $question,
            'product_id' => $productIds[0] ?? null,
            'model' => $models[0] ?? null,
            'models' => $models,
            'tours_count' => count($productIds),
            'pool_estimated_days' => $poolDays,
            'curated_day_count' => count($assembleDays),
            'preferred_regions' => $prepared['preferred_regions'],
            'assemble_filter_note' => $prepared['note'],
            'constraints' => $constraints,
            'max_day_jump_km' => (float) config('services.nominatim.max_day_jump_km', 120),
            'max_day_span_km' => (float) config('services.nominatim.max_day_span_km', 70),
            'match_note' => 'Сборка из PHP-отобранного пула ('.$prepared['before'].' → '.$poolDays.')',
        ];

        $t1 = microtime(true);
        $formatted = $this->formatter->formatFromDayPool($assembleDays, $brief, $systemPrompt);
        $formatMs = (int) round((microtime(true) - $t1) * 1000);

        $tourSummaries = [];
        foreach ($sources as $src) {
            if (! is_array($src)) {
                continue;
            }
            $tourSummaries[] = [
                'product_id' => $src['product_id'] ?? null,
                'model' => $src['model'] ?? null,
                'title' => $src['title'] ?? null,
                'score' => $src['score'] ?? null,
                'meta_lang' => $src['meta_lang'] ?? null,
                'block_count' => $src['block_count'] ?? 0,
                'estimated_days' => $src['estimated_days'] ?? null,
            ];
        }
        if ($tourSummaries === []) {
            foreach ($productIds as $pid) {
                $tourSummaries[] = [
                    'product_id' => $pid,
                    'model' => null,
                    'title' => null,
                    'score' => null,
                    'meta_lang' => null,
                    'block_count' => 0,
                    'estimated_days' => null,
                ];
            }
        }

        $reply = $this->finalizeReply(
            trim($formatted['text']),
            $tourSummaries,
            $poolDays,
            $constraints,
        );

        return [
            'reply' => $reply,
            'tours' => $tourSummaries,
            'curated_days' => $assembleDays,
            'assemble_filter_note' => $prepared['note'],
            'preferred_regions' => $prepared['preferred_regions'],
            'product_id' => $productIds[0] ?? null,
            'model' => $models[0] ?? null,
            'meta_lang' => $constraints['answer_lang'] ?? 'ru',
            'estimated_days' => $poolDays,
            'pool_days' => $poolDays,
            'constraints' => $constraints,
            'search_ms' => 0,
            'format_ms' => $formatMs,
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Legacy: stitch from full tour meta (prefer assembleFromCuratedDays).
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array{
     *   reply: string,
     *   tours: list<array<string, mixed>>,
     *   product_id: int|null,
     *   model: string|null,
     *   meta_lang: string|null,
     *   estimated_days: int|null,
     *   pool_days: int,
     *   constraints: array<string, mixed>,
     *   search_ms: int,
     *   format_ms: int,
     *   total_ms: int
     * }
     */
    public function assembleFromSources(string $question, array $sources, ?string $systemPrompt = null): array
    {
        $t0 = microtime(true);
        $question = trim($question);
        if ($question === '') {
            throw new RuntimeException('Empty question');
        }

        // Prefer new pipeline: curate → assemble from day cards
        $curated = $this->curateFromSources($question, $sources);
        $assembled = $this->assembleFromCuratedDays(
            $question,
            $curated['curated_days'],
            $sources,
            $systemPrompt,
        );
        $assembled['curated_days'] = $curated['curated_days'];
        $assembled['preferred_regions'] = $curated['preferred_regions'];
        $assembled['curate_ms'] = $curated['curate_ms'];
        $assembled['total_ms'] = (int) round((microtime(true) - $t0) * 1000);

        return $assembled;
    }

    /**
     * @param  list<array<string, mixed>>  $tours
     * @param  array<string, mixed>  $constraints
     * @return array{
     *   reply: string,
     *   tours: list<array<string, mixed>>,
     *   product_id: int|null,
     *   model: string|null,
     *   meta_lang: string|null,
     *   estimated_days: int|null,
     *   pool_days: int,
     *   constraints: array<string, mixed>,
     *   search_ms: int,
     *   format_ms: int,
     *   total_ms: int
     * }
     */
    private function assembleFromTours(
        string $question,
        array $tours,
        array $constraints,
        ?string $systemPrompt,
        int $searchMs,
        float $t0,
    ): array {
        $poolDays = 0;
        foreach ($tours as $tour) {
            $poolDays += max(1, (int) ($tour['estimated_days'] ?? 1));
        }

        $perTourChars = (int) max(40000, floor(320000 / max(1, count($tours))));
        $pool = [];
        foreach ($tours as $tour) {
            $clean = $this->retriever->stripNoiseMeta($tour['meta']);
            $pool[] = [
                'product_id' => $tour['product_id'],
                'model' => $tour['model'],
                'title' => $tour['title'],
                'score' => $tour['score'],
                'estimated_days' => $tour['estimated_days'],
                'meta_lang' => $tour['meta_lang'],
                'full_tour' => $this->retriever->compactMeta($clean, $perTourChars),
            ];
        }

        $primary = $tours[0];
        $models = array_values(array_filter(array_map(
            fn (array $t) => $t['model'] ?? null,
            $tours
        )));

        $brief = [
            'question' => $question,
            'product_id' => $primary['product_id'],
            'model' => $primary['model'],
            'models' => $models,
            'title' => $primary['title'],
            'meta_lang' => $primary['meta_lang'],
            'score' => $primary['score'],
            'estimated_days_in_tour' => $primary['estimated_days'],
            'pool_estimated_days' => $poolDays,
            'tours_count' => count($tours),
            'match_note' => $primary['match_note'] ?? null,
            'constraints' => $constraints,
        ];

        $t1 = microtime(true);
        $formatted = $this->formatter->formatPool($pool, $brief, $systemPrompt);
        $formatMs = (int) round((microtime(true) - $t1) * 1000);

        $reply = $this->finalizeReply(
            trim($formatted['text']),
            $tours,
            $poolDays,
            $constraints,
        );

        $tourSummaries = [];
        foreach ($tours as $tour) {
            $blocks = $tour['meta']['blocks'] ?? [];
            $tourSummaries[] = [
                'product_id' => $tour['product_id'],
                'model' => $tour['model'],
                'title' => $tour['title'],
                'score' => $tour['score'],
                'meta_lang' => $tour['meta_lang'],
                'block_count' => is_array($blocks) ? count($blocks) : 0,
                'estimated_days' => $tour['estimated_days'],
            ];
        }

        return [
            'reply' => $reply,
            'tours' => $tourSummaries,
            'product_id' => $primary['product_id'],
            'model' => $primary['model'],
            'meta_lang' => $primary['meta_lang'],
            'estimated_days' => $primary['estimated_days'],
            'pool_days' => $poolDays,
            'constraints' => $constraints,
            'search_ms' => $searchMs,
            'format_ms' => $formatMs,
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: int}
     */
    private function resolveTourPool(string $question, int $tourLimit = 5, ?string $vectorQuery = null): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new RuntimeException('Empty question');
        }

        $constraints = $this->briefParser->parse($question);
        $asked = isset($constraints['days']) && is_int($constraints['days']) ? $constraints['days'] : null;
        $fetchLimit = $asked !== null && $asked >= 8 ? 5 : max(3, $tourLimit);
        $searchText = trim((string) ($vectorQuery ?: $question));
        if ($searchText === '') {
            $searchText = $question;
        }

        $t0 = microtime(true);
        $ranked = $this->retriever->findTours($searchText, $fetchLimit, null, $constraints);
        $tours = $this->retriever->selectTourPool($ranked, $constraints, 5);
        $searchMs = (int) round((microtime(true) - $t0) * 1000);

        if ($tours === []) {
            throw new RuntimeException('Тур не найден в базе/индексе. Укажите model тура или проверьте индексацию.');
        }

        return [$tours, $constraints, $searchMs];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function buildPreviewText(array $meta, int $maxChars = 12000): string
    {
        $compact = $this->retriever->compactMeta($meta, $maxChars);
        $parts = [];
        $used = 0;
        foreach ($compact['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $text = trim((string) ($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $parts[] = $text;
            $used += mb_strlen($text);
            if ($used >= $maxChars) {
                break;
            }
        }

        $out = trim(implode("\n\n", $parts));
        if ($out === '') {
            return '(нет текстовых блоков в meta)';
        }
        if (mb_strlen($out) > $maxChars) {
            return mb_substr($out, 0, $maxChars)."\n…";
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tours
     * @param  array<string, mixed>  $constraints
     */
    private function finalizeReply(string $body, array $tours, int $poolDays, array $constraints): string
    {
        $lines = preg_split('/\R/u', $body) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^⚠️/u', $trim)) {
                continue;
            }
            if (preg_match('/^источник/iu', $trim)) {
                continue;
            }
            if (preg_match('/^собрано\s+из/iu', $trim)) {
                continue;
            }
            if (preg_match('/^итого:/iu', $trim)) {
                continue;
            }
            if (preg_match('/в базе\s+(данных\s+)?(содержится|меньше|\d+)/iu', $trim)) {
                continue;
            }
            if (preg_match('/меньше\s+дней/iu', $trim)) {
                continue;
            }
            if (preg_match('/недостающ|не придумыва/iu', $trim) && ! preg_match('/^\d{1,2}[.\-\/]/u', $trim)) {
                continue;
            }
            // Drop pure model lists like "84, 171" or "model 84"
            if (preg_match('/^(?:model\s*)?\d{2,6}(?:\s*[,;#]\s*(?:model\s*)?\d{2,6})+$/iu', $trim)) {
                continue;
            }
            $kept[] = $line;
        }

        $body = trim(implode("\n", $kept));
        // Russian answers: strip leftover Hebrew letters (e.g. Варונה)
        if (($constraints['answer_lang'] ?? '') === 'ru') {
            $body = (string) preg_replace('/[\x{0590}-\x{05FF}]+/u', '', $body);
            $body = (string) preg_replace('/[ \t]{2,}/u', ' ', $body);
            $body = (string) preg_replace('/ ?([,;—–-]) ?/u', '$1 ', $body);
            $body = trim((string) preg_replace('/\n{3,}/u', "\n\n", $body));
        }
        $dayLines = 0;
        foreach ($kept as $line) {
            if (preg_match('/^\s*\*{0,2}\s*\d{1,2}[.\-\/]\d{1,2}\b/u', trim($line))) {
                $dayLines++;
            }
        }

        $programDays = $dayLines > 0 ? $dayLines : min($poolDays, 31);
        $asked = isset($constraints['days']) && is_int($constraints['days']) ? $constraints['days'] : null;

        $sources = [];
        foreach ($tours as $tour) {
            $bit = 'model '.($tour['model'] ?? '—').' #'.$tour['product_id'];
            $bit .= ' (~'.(int) $tour['estimated_days'].'д)';
            $sources[] = $bit;
        }

        $prefix = [];
        if ($asked !== null && $programDays < $asked) {
            $prefix[] = '⚠️ Собрано '.$programDays.' дн. из '.count($tours)
                .' тур(ов) базы (пул ~'.$poolDays.' дн.), в запросе '.$asked
                .' — больше содержательных дней в найденных JSON не набралось.';
        } else {
            $prefix[] = 'Собрано из '.count($tours).' тур(ов) базы → '.$programDays.' дн. маршрута.';
        }
        $prefix[] = 'Источники: '.implode(' · ', $sources);

        $footer = 'Итого: '.$programDays.' дн. из базы ('.count($tours).' источник(ов)).';
        if ($asked !== null && $programDays < $asked) {
            $footer .= ' Запрос был на '.$asked.' дн.';
        }

        return implode("\n", $prefix)."\n\n".$body."\n\n".$footer;
    }
}
