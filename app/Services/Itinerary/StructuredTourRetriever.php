<?php

namespace App\Services\Itinerary;

use App\Models\Product;
use App\Services\RagDatabaseService;
use App\Services\RagSearchService;
use Illuminate\Support\Facades\DB;

/**
 * Stage 1: vector / model → product_id. Stage 2: load full MySQL meta (all days).
 */
class StructuredTourRetriever
{
    public function __construct(private RagSearchService $rag) {}

    /**
     * @param  array<string, mixed>  $constraints  from QuestionnaireBrief::parse
     * @return list<array{
     *   product_id: int,
     *   model: string|null,
     *   title: string|null,
     *   score: float,
     *   meta: array<string, mixed>,
     *   meta_lang: string,
     *   estimated_days: int,
     *   match_note: string|null
     * }>
     */
    /**
     * Full sample: every tour that mentions any plan location (not top-3 semantic pick).
     *
     * @param  list<string>  $locations
     * @param  array<string, mixed>  $constraints
     * @return list<array<string, mixed>>
     */
    /**
     * Lightweight list only (no MySQL meta / no preview) — safe for hundreds of hits.
     * Full text is hydrated later on translate / extract.
     *
     * @param  list<string>  $locations
     * @param  array<string, mixed>  $constraints
     * @return list<array<string, mixed>>
     */
    public function findToursByLocations(array $locations, array $constraints = [], ?int $maxTours = null): array
    {
        $hits = $this->rag->findProductsByLocationMentions($locations, $maxTours);
        if ($hits === []) {
            return [];
        }

        $candidates = [];
        foreach ($hits as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $matched = is_array($row['matched'] ?? null) ? $row['matched'] : [];
            $routeLines = is_array($row['route_lines'] ?? null) ? $row['route_lines'] : [];
            $routeLines = array_values(array_filter(array_map(
                static fn ($l) => is_string($l) ? trim($l) : '',
                $routeLines
            )));
            $lineCount = (int) ($row['route_line_count'] ?? count($routeLines));
            $candidates[] = [
                'product_id' => $pid,
                'model' => $row['model'] ?? null,
                'title' => $row['title'] ?? null,
                'score' => round((float) ($row['score'] ?? 0), 4),
                'meta_lang' => 'he',
                'estimated_days' => $lineCount > 0 ? $lineCount : null,
                'block_count' => $lineCount,
                'matched_locations' => $matched,
                'route_lines' => array_slice($routeLines, 0, 12),
                'route_line_count' => $lineCount,
                'match_note' => $lineCount > 0
                    ? ('Строк маршрута: '.$lineCount
                        .($matched !== [] ? ' · '.implode(', ', array_slice($matched, 0, 6)) : ''))
                    : null,
                'preview_text' => $routeLines !== [] ? implode("\n", array_slice($routeLines, 0, 12)) : '',
                'preview_deferred' => false,
            ];
        }

        return $candidates;
    }

    /**
     * Load preview_text (+ days/blocks) from MySQL meta for cached lightweight sources.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public function hydrateSourcePreviews(array $sources, string $preferLang = 'ru', int $previewChars = 8000): array
    {
        if ($sources === []) {
            return [];
        }

        $byId = [];
        foreach ($sources as $src) {
            $pid = (int) ($src['product_id'] ?? 0);
            if ($pid > 0) {
                $byId[$pid] = $src;
            }
        }

        foreach (array_chunk(array_keys($byId), 25) as $chunkIds) {
            $products = Product::query()->whereIn('id', $chunkIds)->get()->keyBy('id');
            foreach ($chunkIds as $pid) {
                $src = $byId[$pid];
                $product = $products->get($pid);
                if (! $product) {
                    continue;
                }
                $meta = $this->resolveMeta($product, $preferLang);
                if ($meta === null) {
                    continue;
                }
                $blocks = $meta['meta']['blocks'] ?? [];
                $src['model'] = $product->model !== null ? (string) $product->model : ($src['model'] ?? null);
                $src['meta_lang'] = $meta['lang'];
                $src['estimated_days'] = $this->estimateDayCount($meta['meta']);
                $src['block_count'] = is_array($blocks) ? count($blocks) : 0;
                $src['preview_text'] = $this->compactPreviewFromMeta($meta['meta'], $previewChars);
                $src['preview_deferred'] = false;
                $byId[$pid] = $src;
                unset($meta, $blocks);
            }
            unset($products);
        }

        $ordered = [];
        foreach ($sources as $src) {
            $pid = (int) ($src['product_id'] ?? 0);
            $ordered[] = $byId[$pid] ?? $src;
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function compactPreviewFromMeta(array $meta, int $maxChars = 1800): string
    {
        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            return '';
        }
        $parts = [];
        $used = 0;
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $text = trim((string) ($block['text'] ?? $block['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($used >= $maxChars) {
                break;
            }
            $room = $maxChars - $used;
            if (mb_strlen($text) > $room) {
                $text = mb_substr($text, 0, $room).'…';
            }
            $parts[] = $text;
            $used += mb_strlen($text) + 2;
        }

        return trim(implode("\n\n", $parts));
    }

    public function findTours(string $query, int $limit = 1, ?string $preferLang = null, array $constraints = []): array
    {
        $limit = max(1, min(5, $limit));
        $lang = $preferLang ?? $this->guessLang($query);
        $modelFilter = $this->rag->detectModelFilter($query);

        if ($modelFilter !== null) {
            $byModel = $this->loadByModel($modelFilter, $lang);
            if ($byModel !== null) {
                return [$this->withMatchNote($byModel, $constraints)];
            }
        }

        $result = $this->rag->search($query, max(12, $limit * 4));
        $hits = $result['hits'];
        if ($hits === []) {
            return [];
        }

        $bestByProduct = [];
        foreach ($hits as $hit) {
            $pid = (int) ($hit['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $score = (float) ($hit['score'] ?? 0);
            if (! isset($bestByProduct[$pid]) || $score > (float) $bestByProduct[$pid]['score']) {
                $bestByProduct[$pid] = [
                    'product_id' => $pid,
                    'model' => isset($hit['model']) ? (string) $hit['model'] : null,
                    'title' => isset($hit['title']) ? (string) $hit['title'] : null,
                    'score' => $score,
                ];
            }
        }

        $candidates = [];
        foreach ($bestByProduct as $row) {
            $product = Product::query()->find($row['product_id']);
            if (! $product) {
                continue;
            }
            $meta = $this->resolveMeta($product, $lang);
            if ($meta === null) {
                continue;
            }
            $estimatedDays = $this->estimateDayCount($meta['meta']);
            $fitness = $this->preferenceFitness($meta['meta'], $constraints);
            $candidates[] = [
                'product_id' => (int) $product->id,
                'model' => $product->model !== null ? (string) $product->model : $row['model'],
                'title' => $row['title'],
                'score' => round((float) $row['score'], 4),
                'meta' => $meta['meta'],
                'meta_lang' => $meta['lang'],
                'estimated_days' => $estimatedDays,
                'preference_fitness' => $fitness,
                'match_note' => null,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        $askedDays = isset($constraints['days']) && is_int($constraints['days']) ? $constraints['days'] : null;
        usort($candidates, function (array $a, array $b) use ($askedDays) {
            // Prefer nature / fewer big-city signals when requested
            $fa = (float) ($a['preference_fitness'] ?? 0);
            $fb = (float) ($b['preference_fitness'] ?? 0);
            if (abs($fa - $fb) > 0.05) {
                return $fb <=> $fa;
            }

            if ($askedDays !== null) {
                $aDays = (int) $a['estimated_days'];
                $bDays = (int) $b['estimated_days'];
                $aOk = $aDays >= $askedDays ? 0 : 1;
                $bOk = $bDays >= $askedDays ? 0 : 1;
                if ($aOk !== $bOk) {
                    return $aOk <=> $bOk;
                }
                $da = abs($aDays - $askedDays);
                $db = abs($bDays - $askedDays);
                if ($da !== $db) {
                    return $da <=> $db;
                }
            }

            return $b['score'] <=> $a['score'];
        });

        $top = array_slice($candidates, 0, $limit);
        foreach ($top as $i => $tour) {
            $top[$i] = $this->withMatchNote($tour, $constraints);
            unset($top[$i]['preference_fitness']);
        }

        return $top;
    }

    /**
     * @param  array<string, mixed>  $tour
     * @param  array<string, mixed>  $constraints
     * @return array<string, mixed>
     */
    private function withMatchNote(array $tour, array $constraints): array
    {
        $askedDays = isset($constraints['days']) && is_int($constraints['days']) ? $constraints['days'] : null;
        $n = (int) ($tour['estimated_days'] ?? 0);
        $base = $tour['match_note'] ?? null;
        if ($askedDays !== null && $n > 0 && $n < $askedDays) {
            $tour['match_note'] = trim(($base ? $base.' ' : '').'В туре примерно '.$n
                .' содержательных дн. программы, в запросе '.$askedDays
                .' — недостающие дни НЕ придумывать; вывести только дни из JSON.');
        } elseif ($askedDays !== null && $n > $askedDays) {
            $tour['match_note'] = trim(($base ? $base.' ' : '').'В туре примерно '.$n
                .' дн., нужно '.$askedDays
                .' — взять '.$askedDays.' содержательных дней (приоритет природа), без шопинга если запрещён.');
        }

        return $tour;
    }

    /**
     * @return array{
     *   product_id: int,
     *   model: string|null,
     *   title: string|null,
     *   score: float,
     *   meta: array<string, mixed>,
     *   meta_lang: string,
     *   estimated_days: int,
     *   match_note: string|null
     * }|null
     */
    private function loadByModel(string $modelFilter, string $lang): ?array
    {
        $product = Product::query()->where('model', $modelFilter)->first();
        if (! $product) {
            $product = Product::query()->where('model', 'like', '%'.$modelFilter.'%')->first();
        }
        if (! $product) {
            $conn = RagDatabaseService::CONNECTION;
            try {
                $doc = DB::connection($conn)->table('rag_documents')->where('model', $modelFilter)->first();
                if ($doc) {
                    $product = Product::query()->find((int) $doc->product_id);
                }
            } catch (\Throwable) {
                // RAG optional for model lookup via MySQL
            }
        }
        if (! $product) {
            return null;
        }

        $meta = $this->resolveMeta($product, $lang);
        if ($meta === null) {
            return null;
        }

        $title = null;
        try {
            $conn = RagDatabaseService::CONNECTION;
            $title = DB::connection($conn)->table('rag_documents')
                ->where('product_id', $product->id)
                ->value('title');
        } catch (\Throwable) {
            $title = null;
        }

        return [
            'product_id' => (int) $product->id,
            'model' => $product->model !== null ? (string) $product->model : $modelFilter,
            'title' => $title !== null ? (string) $title : null,
            'score' => 1.0,
            'meta' => $meta['meta'],
            'meta_lang' => $meta['lang'],
            'estimated_days' => $this->estimateDayCount($meta['meta']),
            'match_note' => 'Выбран по model из запроса.',
        ];
    }

    /**
     * Reload full meta for product ids already chosen in preview (stable assemble).
     *
     * @param  list<int>  $productIds
     * @return list<array{
     *   product_id: int,
     *   model: string|null,
     *   title: string|null,
     *   score: float,
     *   meta: array<string, mixed>,
     *   meta_lang: string,
     *   estimated_days: int,
     *   match_note: string|null
     * }>
     */
    public function loadByProductIds(array $productIds, string $preferLang = 'ru'): array
    {
        $out = [];
        $seen = [];
        foreach ($productIds as $rawId) {
            $pid = (int) $rawId;
            if ($pid <= 0 || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $product = Product::query()->find($pid);
            if (! $product) {
                continue;
            }
            $meta = $this->resolveMeta($product, $preferLang);
            if ($meta === null) {
                continue;
            }
            $title = null;
            try {
                $conn = RagDatabaseService::CONNECTION;
                $title = DB::connection($conn)->table('rag_documents')
                    ->where('product_id', $product->id)
                    ->value('title');
            } catch (\Throwable) {
                $title = null;
            }
            $out[] = [
                'product_id' => (int) $product->id,
                'model' => $product->model !== null ? (string) $product->model : null,
                'title' => $title !== null ? (string) $title : null,
                'score' => 1.0,
                'meta' => $meta['meta'],
                'meta_lang' => $meta['lang'],
                'estimated_days' => $this->estimateDayCount($meta['meta']),
                'match_note' => 'Из выбранного сырья preview.',
            ];
        }

        return $out;
    }

    /**
     * Drop TOC / legal / FAQ / car-rental boilerplate blocks before Gemini assemble.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function stripNoiseMeta(array $meta): array
    {
        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            return $meta;
        }

        $kept = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                continue;
            }
            $text = trim((string) ($block['text'] ?? $block['text_he'] ?? $block['text_ru'] ?? ''));
            if ($text === '' || $this->isNoiseText($text)) {
                continue;
            }
            $kept[] = $block;
        }

        $meta['blocks'] = $kept;

        return $meta;
    }

    private function isNoiseText(string $text): bool
    {
        if (str_contains($text, '#_Toc')) {
            return true;
        }

        $sample = mb_substr($text, 0, 800);

        if (preg_match('/Цель маршрутов|מטרת המסלולים|Гимпель|גימפל|Все права защищены|כל הזכויות שמורות/u', $sample) === 1) {
            return true;
        }

        if (preg_match('/Эксклюзивные предложения по аренде|הצעות בלעדיות להשכרת רכב|аренда авто через личного|השכרת רכב.*סוכן/u', $sample) === 1
            && preg_match('/^\s*(?:יום|День|Понедельник|Вторник|Среда|Четверг|Пятница|Суббота|Воскресенье|\d{1,2}[.\-\/])/u', $sample) !== 1) {
            return true;
        }

        if (preg_match('/Как правильно читать маршрут|Не слишком ли перегружен|Нужно ли бронировать|Обязательно ли заранее|Что касается SIM|Что насчет страховки|Почему в маршруте много поездок|איך לקרוא את המסלול|האם המסלול עמוס/u', $sample) === 1) {
            return true;
        }

        if (preg_match('/^Содержание\b|^תוכן עניינים\b/u', $sample) === 1) {
            return true;
        }

        // Dense TOC lines: many short "date — placeN" with trailing page numbers
        $lines = preg_split('/\R/u', $text) ?: [];
        if (count($lines) >= 6) {
            $tocish = 0;
            foreach (array_slice($lines, 0, 20) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/\d{1,2}[\/.]\d{1,2}.+\d{1,3}\s*$/u', $line) === 1) {
                    $tocish++;
                }
            }
            if ($tocish >= 5) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count itinerary program days (headers), ignore TOC / random dates in body.
     *
     * @param  array<string, mixed>  $meta
     */
    public function estimateDayCount(array $meta): int
    {
        $blocks = array_values(array_filter(
            is_array($meta['blocks'] ?? null) ? $meta['blocks'] : [],
            fn ($b) => is_array($b)
        ));
        if ($blocks === []) {
            return 0;
        }

        $dayKeys = [];
        $hebrewWeekdays = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        $seq = 0;
        $total = count($blocks);

        for ($i = 0; $i < $total; $i++) {
            $block = $blocks[$i];
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                continue;
            }
            $text = trim((string) ($block['text'] ?? $block['text_he'] ?? $block['text_ru'] ?? ''));
            if ($text === '' || str_contains($text, '#_Toc')) {
                continue;
            }

            $split = preg_split('/\R/u', $text) ?: [];
            $firstLine = trim((string) ($split[0] ?? ''));
            if ($firstLine === '' || mb_strlen($firstLine) > 160) {
                continue;
            }
            if (substr_count($firstLine, '/') + substr_count($firstLine, '.') > 6) {
                continue;
            }

            $key = null;
            if (preg_match('/^(?:יום)\s+('.$hebrewWeekdays.')\b/u', $firstLine, $m)) {
                $key = 'he_wd_'.$m[1].'_'.$seq;
                $seq++;
            } elseif (preg_match('/^(?:יום)\s*[:\-]?\s*(\d{1,2})\b/u', $firstLine, $m)) {
                $key = 'he_n_'.$m[1];
            } elseif (preg_match('/^(?:день|day)\s*[:\-]?\s*(\d{1,2})\b/iu', $firstLine, $m)) {
                $key = 'n_'.$m[1];
            } elseif (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?\b/u', $firstLine, $m)) {
                $rest = trim(mb_substr($firstLine, mb_strlen($m[0])));
                if ($rest === '' || mb_strlen($rest) <= 80) {
                    $key = 'd_'.(int) $m[1].'_'.(int) $m[2];
                }
            }

            if ($key === null) {
                continue;
            }

            // Count headers that have body in the same block or in following blocks
            $sameRest = trim(mb_substr($text, mb_strlen($firstLine)));
            $hasBody = mb_strlen($sameRest) >= 20 || $this->hasFollowingContent($blocks, $i + 1, 12);
            if (! $hasBody) {
                continue;
            }

            $dayKeys[$key] = true;
        }

        $count = count($dayKeys);
        if ($count >= 2) {
            return min(31, $count);
        }

        $numbered = [];
        foreach ($blocks as $block) {
            $text = trim((string) ($block['text'] ?? $block['text_he'] ?? $block['text_ru'] ?? ''));
            if ($text === '' || mb_strlen($text) > 120 || str_contains($text, '#_Toc')) {
                continue;
            }
            if (preg_match_all('/(?:יום|день|day)\s*[:\-]?\s*(\d{1,2})\b/iu', $text, $mm)) {
                foreach ($mm[1] as $n) {
                    $numbered['n'.$n] = true;
                }
            }
        }
        if (count($numbered) >= 2) {
            return min(31, count($numbered));
        }

        return max(1, $count);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function hasFollowingContent(array $blocks, int $from, int $lookAhead): bool
    {
        $end = min(count($blocks), $from + $lookAhead);
        $hebrewWeekdays = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        for ($j = $from; $j < $end; $j++) {
            $b = $blocks[$j];
            $type = (string) ($b['type'] ?? 'text');
            if ($type === 'image') {
                return true;
            }
            if ($type === 'link' && ! empty($b['url'])) {
                return true;
            }
            $t = trim((string) ($b['text'] ?? $b['text_he'] ?? $b['text_ru'] ?? ''));
            if ($t === '' || str_contains($t, '#_Toc')) {
                continue;
            }
            $line = trim((string) ((preg_split('/\R/u', $t) ?: [''])[0]));
            // Next day header → stop (no body for previous)
            $isNextDayHeader = preg_match('/^(?:יום)\s+('.$hebrewWeekdays.')\b/u', $line)
                || preg_match('/^(?:יום|день|day)\s*[:\-]?\s*\d{1,2}\b/iu', $line)
                || (preg_match('/^\d{1,2}[.\-\/]\d{1,2}\b/u', $line) === 1 && mb_strlen($line) <= 100);
            if ($isNextDayHeader) {
                return false;
            }
            if (mb_strlen($t) >= 20) {
                return true;
            }
        }

        return false;
    }

    /**
     * Higher = better match for nature / no-big-cities constraints.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $constraints
     */
    public function preferenceFitness(array $meta, array $constraints): float
    {
        $wantNature = ! empty($constraints['nature']) || ! empty($constraints['no_big_cities']);
        if (! $wantNature) {
            return 0.0;
        }

        $text = $this->metaPlainText($meta, 12000);
        $natureHits = 0;
        foreach (['גארדה', 'como', 'קומו', 'tremosine', 'molveno', 'limone', 'varenna', 'bellagio', 'waterfall', 'מפל', 'אגם', 'lake', 'парк', 'nature', 'הליכה', 'trek', 'гарда', 'комо', 'озеро'] as $w) {
            if (str_contains(mb_strtolower($text), mb_strtolower($w))) {
                $natureHits++;
            }
        }
        $cityHits = 0;
        foreach (['duomo', 'דואומו', 'rinascente', 'navigli', 'san siro', 'montenapoleone', 'outlet', 'foxtown', 'franciacorta village', 'шопинг', 'аутлет', 'дуомо'] as $w) {
            if (str_contains(mb_strtolower($text), mb_strtolower($w))) {
                $cityHits++;
            }
        }

        return ($natureHits * 0.15) - ($cityHits * 0.25);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function metaPlainText(array $meta, int $maxChars): string
    {
        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            return '';
        }
        $parts = [];
        $used = 0;
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') === 'image') {
                continue;
            }
            $t = trim((string) ($block['text'] ?? $block['text_he'] ?? $block['text_ru'] ?? ''));
            if ($t === '') {
                continue;
            }
            $parts[] = $t;
            $used += mb_strlen($t);
            if ($used >= $maxChars) {
                break;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @return array{meta: array<string, mixed>, lang: string}|null
     */
    private function resolveMeta(Product $product, string $preferLang): ?array
    {
        if ($preferLang === 'ru') {
            $ru = $product->ruMeta();
            if ($ru !== null) {
                return ['meta' => $ru, 'lang' => 'ru'];
            }
        }

        $he = $product->heMeta();
        if ($he !== null) {
            return ['meta' => $he, 'lang' => 'he'];
        }

        $ru = $product->ruMeta();
        if ($ru !== null) {
            return ['meta' => $ru, 'lang' => 'ru'];
        }

        return null;
    }

    private function guessLang(string $query): string
    {
        return preg_match('/[А-Яа-яЁё]/u', $query) === 1 ? 'ru' : 'he';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function compactMeta(array $meta, ?int $maxChars = null): array
    {
        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            $blocks = [];
        }

        $compactBlocks = [];
        $used = 0;
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                // Skip images in multi-tour pools to save tokens
                if ($maxChars !== null) {
                    continue;
                }
                $path = (string) ($block['path'] ?? $block['src'] ?? '');
                $compactBlocks[] = [
                    'type' => 'image',
                    'ref' => $path !== '' ? '[image] '.$path : '[image]',
                ];

                continue;
            }
            $text = (string) ($block['text'] ?? $block['text_he'] ?? $block['text_ru'] ?? '');
            if ($text === '') {
                continue;
            }
            $row = [
                'type' => $type,
                'text' => $text,
                'url' => isset($block['url']) ? (string) $block['url'] : null,
            ];
            $add = mb_strlen($text) + 24;
            if ($maxChars !== null && $used + $add > $maxChars && $compactBlocks !== []) {
                break;
            }
            $compactBlocks[] = $row;
            $used += $add;
        }

        return [
            'format' => $meta['format'] ?? 'docx_sequential_v1',
            'source_file' => $meta['source_file'] ?? null,
            'block_count' => count($compactBlocks),
            'blocks' => $compactBlocks,
        ];
    }

    /**
     * Pick 1–5 tours so estimated days can cover the questionnaire when possible.
     *
     * @param  list<array<string, mixed>>  $ranked
     * @param  array<string, mixed>  $constraints
     * @return list<array<string, mixed>>
     */
    public function selectTourPool(array $ranked, array $constraints, int $maxTours = 5): array
    {
        if ($ranked === []) {
            return [];
        }

        $asked = isset($constraints['days']) && is_int($constraints['days']) ? $constraints['days'] : null;
        $maxTours = max(1, min(5, $maxTours));

        // Explicit model → only that tour
        $note0 = (string) ($ranked[0]['match_note'] ?? '');
        if (str_contains($note0, 'Выбран по model') && count($ranked) === 1) {
            return $ranked;
        }

        $pool = [];
        $sumDays = 0;
        foreach ($ranked as $tour) {
            $pool[] = $tour;
            $sumDays += max(1, (int) ($tour['estimated_days'] ?? 1));
            if (count($pool) >= $maxTours) {
                break;
            }
            if ($asked !== null && $sumDays >= $asked && count($pool) >= 2) {
                break;
            }
        }

        // For long trips always try at least 2–3 sources if available
        if ($asked !== null && $asked >= 8 && count($pool) < 3 && count($ranked) > count($pool)) {
            $pool = array_slice($ranked, 0, min($maxTours, max(3, count($pool))));
        }

        return $pool;
    }
}
