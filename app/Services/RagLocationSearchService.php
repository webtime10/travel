<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Language;
use App\Models\Location;
use App\Models\Manufacturer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SQL shelves first, then cosine on location chunks. Returns catalog cards, not a tour.
 */
class RagLocationSearchService
{
    private const MAX_SCORE_CHUNKS = 2500;

    private const MAX_RESULTS = 48;

    public function __construct(
        private GeminiEmbeddingService $embeddings,
        private RagDatabaseService $ragDb,
        private LocationDayPacker $dayPacker,
        private LocationCandidateFilter $candidateFilter,
    ) {}

    /**
     * @return array{
     *   locations: list<array<string, mixed>>,
     *   filters: array<string, mixed>,
     *   scanned: int,
     *   candidates: int,
     *   embed_ms: int,
     *   search_ms: int
     * }
     */
    public function search(string $query, int $limit = 36): array
    {
        $query = trim($query);
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        $empty = [
            'locations' => [],
            'filters' => ['country' => null, 'regions' => [], 'blocks' => []],
            'scanned' => 0,
            'candidates' => 0,
            'embed_ms' => 0,
            'search_ms' => 0,
        ];
        if ($query === '') {
            return $empty;
        }

        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false) || ! ($status['schema_ready'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not ready: '.($status['message'] ?? ''));
        }

        $blocks = $this->detectDayBlocks($query);
        if ($blocks === []) {
            $one = $this->searchOnePool($query, $limit, $this->parseFilters($query));
            $filtered = $this->candidateFilter->filter($one['locations']);
            $one['locations'] = $filtered['locations'];
            $one['filters']['dropped'] = $filtered['dropped'];
            $packed = $this->dayPacker->pack($one['locations'], $query);
            if (($packed['day_plan'] ?? []) !== []) {
                $one['locations'] = $packed['locations'];
                $one['filters']['calendar'] = $packed['calendar'];
                $one['filters']['day_plan'] = $packed['day_plan'];
                $one['filters']['blocks'] = array_map(fn (array $b) => [
                    'key' => $b['key'],
                    'label' => $b['label'],
                    'from' => $b['from'] ?? null,
                    'to' => $b['to'] ?? null,
                    'count' => (int) ($b['count'] ?? 0),
                ], $packed['blocks']);
            }

            return $one;
        }

        $blocks = $this->attachCalendar($query, $blocks);
        $bases = [];
        $places = [];
        $scanned = 0;
        $candidates = 0;
        $embedMs = 0;
        $tSearch = microtime(true);
        $perBlock = max(6, (int) ceil($limit / max(1, count($blocks))));

        foreach ($blocks as $block) {
            foreach ($this->hydrate($this->forceBases($block)) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0 && ! isset($bases[$id]) && ! isset($places[$id])) {
                    $bases[$id] = $this->stampBlock($row, $block);
                }
            }
            foreach ($this->hydrate($this->forceAnchors($block)) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0 || isset($bases[$id]) || isset($places[$id]) || $this->isOffBlockRow($block['key'], $row)) {
                    continue;
                }
                $places[$id] = $this->stampBlock($row, $block);
            }
            $part = $this->searchOnePool($block['embed'], $perBlock + 6, $block['filters'], $block);
            $embedMs += $part['embed_ms'];
            $scanned += $part['scanned'];
            $candidates += $part['candidates'];
            $taken = 0;
            foreach ($part['locations'] as $row) {
                $id = (int) $row['id'];
                if ($id <= 0 || isset($bases[$id]) || isset($places[$id]) || $this->isOffBlockRow($block['key'], $row)) {
                    continue;
                }
                $places[$id] = $this->stampBlock($row, $block);
                $taken++;
                if ($taken >= $perBlock) {
                    break;
                }
            }
        }

        $searchMs = (int) round((microtime(true) - $tSearch) * 1000);
        $list = array_merge(array_values($bases), array_values($places));
        usort($list, function (array $a, array $b) use ($blocks) {
            $order = array_flip(array_column($blocks, 'key'));
            $oa = $order[$a['block'] ?? ''] ?? 99;
            $ob = $order[$b['block'] ?? ''] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $ba = ! empty($a['is_base']) ? 0 : (($a['role'] ?? '') === 'anchor' ? 1 : 2);
            $bb = ! empty($b['is_base']) ? 0 : (($b['role'] ?? '') === 'anchor' ? 1 : 2);
            if ($ba !== $bb) {
                return $ba <=> $bb;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        $keep = [];
        $placeCount = 0;
        $must = count(array_filter($list, fn (array $row) => ! empty($row['is_base']) || ($row['role'] ?? '') === 'anchor'));
        $placeCap = max(0, $limit - $must);
        foreach ($list as $row) {
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'anchor') {
                $keep[] = $row;
                continue;
            }
            if ($placeCount >= $placeCap) {
                continue;
            }
            $keep[] = $row;
            $placeCount++;
        }
        $list = $keep;
        $filtered = $this->candidateFilter->filter($list);
        $list = $filtered['locations'];

        $packed = $this->dayPacker->pack($list, $query);
        $filters = $this->parseFilters($query);
        $filters['dropped'] = $filtered['dropped'];
        if (($packed['day_plan'] ?? []) !== []) {
            $list = $packed['locations'];
            $filters['calendar'] = $packed['calendar'];
            $filters['day_plan'] = $packed['day_plan'];
            $filters['blocks'] = array_map(fn (array $b) => [
                'key' => $b['key'],
                'label' => $b['label'],
                'from' => $b['from'] ?? null,
                'to' => $b['to'] ?? null,
                'count' => (int) ($b['count'] ?? 0),
            ], $packed['blocks']);
        } else {
            $filters['calendar'] = $this->calendarLines($blocks);
            $filters['blocks'] = array_map(fn (array $b) => [
                'key' => $b['key'],
                'label' => $b['label'],
                'from' => $b['date_from'] ?? null,
                'to' => $b['date_to'] ?? null,
                'count' => count(array_filter($list, fn ($r) => ($r['block'] ?? '') === $b['key'])),
            ], $blocks);
        }

        return [
            'locations' => $list,
            'filters' => $filters,
            'scanned' => $scanned,
            'candidates' => $candidates,
            'embed_ms' => $embedMs,
            'search_ms' => $searchMs,
        ];
    }

    /**
     * Сырые релевантные POI без CandidateFilter / DayPacker (для Gemini-сценария).
     *
     * @return array{
     *   locations: list<array<string, mixed>>,
     *   filters: array<string, mixed>,
     *   scanned: int,
     *   candidates: int,
     *   embed_ms: int,
     *   search_ms: int
     * }
     */
    public function searchRaw(string $query, int $limit = 48): array
    {
        $query = trim($query);
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        $empty = [
            'locations' => [],
            'filters' => ['country' => null, 'regions' => [], 'blocks' => []],
            'scanned' => 0,
            'candidates' => 0,
            'embed_ms' => 0,
            'search_ms' => 0,
        ];
        if ($query === '') {
            return $empty;
        }

        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false) || ! ($status['schema_ready'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not ready: '.($status['message'] ?? ''));
        }

        $blocks = $this->detectDayBlocks($query);
        if ($blocks === []) {
            return $this->searchOnePool($query, $limit, $this->parseFilters($query));
        }

        $blocks = $this->attachCalendar($query, $blocks);
        $bases = [];
        $places = [];
        $scanned = 0;
        $candidates = 0;
        $embedMs = 0;
        $tSearch = microtime(true);
        $perBlock = max(6, (int) ceil($limit / max(1, count($blocks))));

        foreach ($blocks as $block) {
            foreach ($this->hydrate($this->forceBases($block)) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0 && ! isset($bases[$id]) && ! isset($places[$id])) {
                    $bases[$id] = $this->stampBlock($row, $block);
                }
            }
            foreach ($this->hydrate($this->forceAnchors($block)) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0 || isset($bases[$id]) || isset($places[$id]) || $this->isOffBlockRow($block['key'], $row)) {
                    continue;
                }
                $places[$id] = $this->stampBlock($row, $block);
            }
            $part = $this->searchOnePool($block['embed'], $perBlock + 6, $block['filters'], $block);
            $embedMs += $part['embed_ms'];
            $scanned += $part['scanned'];
            $candidates += $part['candidates'];
            $taken = 0;
            foreach ($part['locations'] as $row) {
                $id = (int) $row['id'];
                if ($id <= 0 || isset($bases[$id]) || isset($places[$id]) || $this->isOffBlockRow($block['key'], $row)) {
                    continue;
                }
                $places[$id] = $this->stampBlock($row, $block);
                $taken++;
                if ($taken >= $perBlock) {
                    break;
                }
            }
        }

        $searchMs = (int) round((microtime(true) - $tSearch) * 1000);
        $list = array_merge(array_values($bases), array_values($places));
        usort($list, function (array $a, array $b) use ($blocks) {
            $order = array_flip(array_column($blocks, 'key'));
            $oa = $order[$a['block'] ?? ''] ?? 99;
            $ob = $order[$b['block'] ?? ''] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $ba = ! empty($a['is_base']) ? 0 : (($a['role'] ?? '') === 'anchor' ? 1 : 2);
            $bb = ! empty($b['is_base']) ? 0 : (($b['role'] ?? '') === 'anchor' ? 1 : 2);
            if ($ba !== $bb) {
                return $ba <=> $bb;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        $keep = [];
        $placeCount = 0;
        $must = count(array_filter($list, fn (array $row) => ! empty($row['is_base']) || ($row['role'] ?? '') === 'anchor'));
        $placeCap = max(0, $limit - $must);
        foreach ($list as $row) {
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'anchor') {
                $keep[] = $row;
                continue;
            }
            if ($placeCount >= $placeCap) {
                continue;
            }
            $keep[] = $row;
            $placeCount++;
        }

        $filters = $this->parseFilters($query);
        $filters['calendar'] = $this->calendarLines($blocks);
        $filters['blocks'] = array_map(fn (array $b) => [
            'key' => $b['key'],
            'label' => $b['label'],
            'from' => $b['date_from'] ?? null,
            'to' => $b['date_to'] ?? null,
            'count' => count(array_filter($keep, fn ($r) => ($r['block'] ?? '') === $b['key'])),
        ], $blocks);

        return [
            'locations' => $keep,
            'filters' => $filters,
            'scanned' => $scanned,
            'candidates' => $candidates,
            'embed_ms' => $embedMs,
            'search_ms' => $searchMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $block
     * @return array{
     *   locations: list<array<string, mixed>>,
     *   filters: array<string, mixed>,
     *   scanned: int,
     *   candidates: int,
     *   embed_ms: int,
     *   search_ms: int
     * }
     */
    private function searchOnePool(string $query, int $limit, array $filters, ?array $block = null): array
    {
        $candidateIds = $this->sqlLocationIds($filters);
        $t0 = microtime(true);
        $queryVector = $this->embeddings->embedOne(mb_substr($query, 0, 4000));
        $embedMs = (int) round((microtime(true) - $t0) * 1000);

        $conn = RagDatabaseService::CONNECTION;
        $t1 = microtime(true);
        $rows = $this->loadChunks($conn, $candidateIds);
        $best = [];
        $scanned = 0;
        $needles = $block['needles'] ?? [];

        foreach ($rows as $row) {
            $scanned++;
            $locationId = (int) ($row->location_id ?? 0);
            if ($locationId <= 0) {
                continue;
            }
            $vector = $this->decodeEmbedding($row->embedding);
            if ($vector === null) {
                continue;
            }
            $content = (string) ($row->content_he ?? '');
            $title = (string) ($row->title ?? '');
            $score = $this->cosineSimilarity($queryVector, $vector);
            $score += $this->keywordBoost($query, $content, $title);
            $score += $this->needleBoost($needles, $content, $title);
            $score -= $this->noisePenalty($content, $title);
            $score -= $this->offBlockPenalty((string) ($block['key'] ?? ''), $content, $title);
            if (($row->block_type ?? '') === 'header' && preg_match('/Model:\s*\d/u', $content)) {
                $score -= 0.06;
            }
            if ($score <= 0) {
                continue;
            }
            if (! isset($best[$locationId]) || $score > $best[$locationId]['score']) {
                $best[$locationId] = [
                    'location_id' => $locationId,
                    'score' => $score,
                    'snippet' => mb_substr($content, 0, 320),
                    'block_type' => (string) ($row->block_type ?? ''),
                    'block' => $block['key'] ?? null,
                    'block_label' => $block['label'] ?? null,
                ];
            }
        }

        uasort($best, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $top = array_slice(array_values($best), 0, $limit);
        $searchMs = (int) round((microtime(true) - $t1) * 1000);
        $hydrated = $this->hydrate($top);
        if ($block) {
            foreach ($hydrated as &$row) {
                $row['block'] = $block['key'];
                $row['block_label'] = $block['label'];
            }
            unset($row);
        }

        return [
            'locations' => $hydrated,
            'filters' => $filters,
            'scanned' => $scanned,
            'candidates' => count($candidateIds),
            'embed_ms' => $embedMs,
            'search_ms' => $searchMs,
        ];
    }

    /**
     * @return list<array{key: string, label: string, embed: string, filters: array<string, mixed>, needles: list<string>, bases: list<string>}>
     */
    private function detectDayBlocks(string $query): array
    {
        $q = mb_strtolower($query);
        $longNorth = $this->isLongNorthTrip($query);
        $hasDolomiti = (bool) preg_match('/доломит|dolomit/ui', $query);
        $hasGarda = (bool) preg_match('/гарда|garda|рива|лимоне|тенно|вароне|ледро|мальчезин/ui', $query);
        $hasGardena = (bool) preg_match('/ортиз|ortisei|гарден|gardena|карецц|carezza|мармолад|marmolada|мольвен|molveno|товел|tovel/ui', $query);
        $hasEast = (bool) preg_match('/бруник|brunico|брай|braies|pragser|мисурин|misurina|кортин|cortina|кандид|candido|tre cime|три пик|drei zinnen/ui', $query);

        $want = [];
        if ($hasGarda || ($hasDolomiti && $longNorth)) {
            $want[] = 'garda';
        }
        if ($hasGardena || $hasDolomiti) {
            $want[] = 'gardena';
        }
        if ($hasEast || ($hasDolomiti && $longNorth)) {
            $want[] = 'east';
        }
        if ($want === []) {
            return [];
        }

        $catalog = $this->dayBlockCatalog();
        $out = [];
        foreach ($want as $key) {
            $block = $catalog[$key] ?? null;
            if ($block) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{key: string, label: string, embed: string, filters: array<string, mixed>, needles: list<string>, bases: list<string>}>
     */
    private function dayBlockCatalog(): array
    {
        $gardaRegions = ['IT:trentino', 'IT:veneto', 'IT:lombardia'];
        $doloRegions = ['IT:trentino', 'IT:veneto'];

        return [
            'garda' => [
                'key' => 'garda',
                'label' => 'Гарда',
                'embed' => 'Riva del Garda Limone sul Garda Lago di Tenno Varone waterfall Ledro Nardis Busatte north Lake Garda',
                'filters' => $this->filtersForRegions('IT', $gardaRegions),
                'needles' => ['garda', 'limone', 'tenno', 'varone', 'ledro', 'nardis', 'busatte', 'ampola', 'ponale', 'לימונה', 'טנו', 'וארנה', 'לדרו', 'נארדיס', 'בוסאטה', 'פונל'],
                'bases' => ['riva del garda', 'ריבה דל', 'рива-дель', 'рива дель'],
                'anchors' => [
                    ['לימונה סול', 'limone sul'],
                    ['busatte to tempesta', 'בוסאטה', 'busatte'],
                    ['טנו', 'tenno'],
                    ['וארנה', 'varone'],
                    ['מפל נארדיס', 'nardis'],
                    ['אגם לדרו', 'ledro'],
                    ['ampola', 'dampola'],
                    ['פונל', 'ponale'],
                ],
            ],
            'gardena' => [
                'key' => 'gardena',
                'label' => 'Ортизей',
                'embed' => 'Ortisei Val Gardena Lago di Carezza Marmolada Molveno Tovel Dolomites',
                'filters' => $this->filtersForRegions('IT', $doloRegions),
                'needles' => ['ortisei', 'gardena', 'carezza', 'karersee', 'marmolada', 'molveno', 'tovel', 'אורטיזיי', 'קארצה', 'מרמולדה', 'מולבנו', 'טובל'],
                'bases' => ['ortisei', 'ортизей', 'ортизеи', 'אורטיזיי'],
                'anchors' => [
                    ['אגם מולבנו', 'molveno'],
                    ['אגם טובל', 'tovel'],
                    ['אגם קארצה', 'lago di carezza', 'carezza'],
                    ['יום של מרמולדה', 'marmolada', 'מרמולדה'],
                ],
            ],
            'east' => [
                'key' => 'east',
                'label' => 'Брунико',
                'embed' => 'Brunico Braies Pragser Wildsee Tre Cime Misurina Cortina San Candido Dobbiaco Dolomites',
                'filters' => $this->filtersForRegions('IT', $doloRegions),
                'needles' => ['brunico', 'braies', 'pragser', 'misurina', 'cortina', 'candido', 'dobbiaco', 'cadini', 'tre cime', 'drei zinnen', 'בראייס', 'מיזורינה', 'סן קנדידו', 'דוביאקו', '3 פסגות'],
                'bases' => ['brunico', 'bruneck', 'бруник', 'ברוניקו'],
                'anchors' => [
                    ['אגם בראייס', 'braies'],
                    ['מיזורינה', 'misurina'],
                    ['3 פסגות', 'tre cime'],
                    ['עיירה סן קנדידו', 'san candido', 'סן קנדידו'],
                    ['אגם דוביאקו', 'dobbiaco'],
                    ['5 פסגות דולומיטים', '5 פסגות'],
                    ['בורגטו', 'borghetto'],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $regionKeys
     * @return array{country: string|null, regions: list<string>, manufacturer_id: int|null, category_ids: list<int>}
     */
    private function filtersForRegions(string $country, array $regionKeys): array
    {
        $manufacturerId = (int) (Manufacturer::query()->where('code', $country)->value('id') ?? 0);

        return [
            'country' => $country,
            'regions' => $regionKeys,
            'manufacturer_id' => $manufacturerId > 0 ? $manufacturerId : null,
            'category_ids' => $this->categoryIdsForKeys($regionKeys),
        ];
    }

    private function isLongNorthTrip(string $query): bool
    {
        if (preg_match('/\b(\d{1,2})\s*[\.\/\-]\s*(\d{1,2})\b.+\b(\d{1,2})\s*[\.\/\-]\s*(\d{1,2})\b/u', $query, $m)) {
            $a = ((int) $m[2]) * 31 + (int) $m[1];
            $b = ((int) $m[4]) * 31 + (int) $m[3];

            return abs($b - $a) >= 7;
        }
        if (preg_match('/\b(\d{1,2})\s*по\s*(\d{1,2})\b/ui', $query, $m)) {
            return abs(((int) $m[2]) - ((int) $m[1])) >= 7;
        }

        return (bool) preg_match('/\b(8|9|10|11|12)\s*дн/ui', $query);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function attachCalendar(string $query, array $blocks): array
    {
        $days = $this->parseTripDays($query);
        $n = count($blocks);
        if ($n === 0) {
            return $blocks;
        }
        if ($days === []) {
            $days = $this->defaultCalendarForBlocks($n);
        }

        $spans = $this->splitCalendar($days, $n);
        foreach ($blocks as $i => $block) {
            $span = $spans[$i] ?? ['from' => null, 'to' => null, 'nights' => 0];
            $blocks[$i]['date_from'] = $span['from'];
            $blocks[$i]['date_to'] = $span['to'];
            $blocks[$i]['nights'] = $span['nights'];
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function parseTripDays(string $query): array
    {
        if (! preg_match('/\b(\d{1,2})\s*[\.\/]\s*(\d{1,2})\b.+\b(\d{1,2})\s*[\.\/]\s*(\d{1,2})\b/u', $query, $m)) {
            return [];
        }
        $d1 = (int) $m[1];
        $mo1 = (int) $m[2];
        $d2 = (int) $m[3];
        $mo2 = (int) $m[4];
        if ($d1 < 1 || $d1 > 31 || $d2 < 1 || $d2 > 31 || $mo1 < 1 || $mo1 > 12 || $mo2 < 1 || $mo2 > 12) {
            return [];
        }
        $year = (int) date('Y');
        $start = strtotime(sprintf('%04d-%02d-%02d', $year, $mo1, $d1));
        $end = strtotime(sprintf('%04d-%02d-%02d', $year, $mo2, $d2));
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $out = [];
        for ($t = $start; $t <= $end; $t += 86400) {
            $out[] = date('d.m', $t);
            if (count($out) > 21) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function defaultCalendarForBlocks(int $n): array
    {
        $start = strtotime(date('Y').'-08-14');
        $len = $n === 3 ? 10 : max(3, $n * 3);
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $out[] = date('d.m', $start + $i * 86400);
        }

        return $out;
    }

    /**
     * @param  list<string>  $days
     * @return list<array{from: ?string, to: ?string, nights: int}>
     */
    private function splitCalendar(array $days, int $n): array
    {
        $total = count($days);
        if ($total === 0 || $n <= 0) {
            return array_fill(0, max(0, $n), ['from' => null, 'to' => null, 'nights' => 0]);
        }
        if ($n === 3 && $total >= 10) {
            $sizes = [3, 3, $total - 6];
        } elseif ($n === 3) {
            $first = max(2, (int) floor($total / 3));
            $sizes = [$first, $first, $total - 2 * $first];
        } else {
            $base = intdiv($total, $n);
            $extra = $total % $n;
            $sizes = [];
            for ($i = 0; $i < $n; $i++) {
                $sizes[] = $base + ($i < $extra ? 1 : 0);
            }
        }

        $out = [];
        $offset = 0;
        foreach ($sizes as $size) {
            $size = max(1, (int) $size);
            $chunk = array_slice($days, $offset, $size);
            $offset += $size;
            $out[] = [
                'from' => $chunk[0] ?? null,
                'to' => $chunk[count($chunk) - 1] ?? null,
                'nights' => count($chunk),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private function calendarLines(array $blocks): array
    {
        $lines = [];
        foreach ($blocks as $block) {
            $from = $block['date_from'] ?? '?';
            $to = $block['date_to'] ?? '?';
            $nights = (int) ($block['nights'] ?? 0);
            $lines[] = $block['label'].': '.$from.'–'.$to.($nights ? ' ('.$nights.' дн.)' : '');
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function stampBlock(array $row, array $block): array
    {
        $row['block'] = $block['key'];
        $row['block_label'] = $block['label'];
        $row['date_from'] = $block['date_from'] ?? null;
        $row['date_to'] = $block['date_to'] ?? null;
        $row['role'] = ! empty($row['is_base']) ? 'base' : ($row['role'] ?? 'place');
        $row['is_base'] = ! empty($row['is_base']);
        if (($row['role'] ?? '') === 'anchor') {
            $row['is_base'] = false;
        }

        return $row;
    }

    /**
     * @param  array{bases?: list<string>, key: string, label: string}  $block
     * @return list<array<string, mixed>>
     */
    private function forceBases(array $block): array
    {
        $needles = $block['bases'] ?? [];
        if ($needles === []) {
            return [];
        }
        $id = $this->pickBestNamedId($needles);
        if ($id <= 0) {
            return [];
        }

        return [[
            'location_id' => $id,
            'score' => 1.05,
            'snippet' => 'база ночёвки блока «'.$block['label'].'»',
            'block_type' => 'base',
            'block' => $block['key'],
            'block_label' => $block['label'],
            'role' => 'base',
            'is_base' => true,
        ]];
    }

    /**
     * @param  array{anchors?: list<list<string>>, key: string, label: string}  $block
     * @return list<array<string, mixed>>
     */
    private function forceAnchors(array $block): array
    {
        $out = [];
        $seen = [];
        foreach ($block['anchors'] ?? [] as $needles) {
            $needles = array_values(array_filter((array) $needles, fn ($n) => mb_strlen((string) $n) >= 3));
            if ($needles === []) {
                continue;
            }
            $id = $this->pickBestNamedId($needles, false);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'location_id' => $id,
                'score' => 0.98,
                'snippet' => 'опорная точка блока «'.$block['label'].'»',
                'block_type' => 'anchor',
                'block' => $block['key'],
                'block_label' => $block['label'],
                'role' => 'anchor',
                'is_base' => false,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $needles
     */
    private function pickBestNamedId(array $needles, bool $includeOvernight = true): int
    {
        $ids = $this->idsByNeedles($needles);
        if ($includeOvernight) {
            $ids = array_values(array_unique(array_merge($ids, $this->overnightIds($needles))));
        }
        if ($ids === []) {
            return 0;
        }
        $locs = Location::query()->with('descriptions')->whereIn('id', $ids)->get();
        $bestId = 0;
        $bestScore = -999;
        foreach ($locs as $loc) {
            $name = mb_strtolower((string) ($loc->descriptions->first()?->name ?? $loc->model ?? ''));
            $score = 0;
            foreach ($needles as $n) {
                $n = mb_strtolower((string) $n);
                if ($n !== '' && str_contains($name, $n)) {
                    $score += mb_strlen($n) >= 8 ? 8 : 5;
                }
            }
            if (preg_match('/שייט|boat|катер|הפוך|funivia|funbob|אופניים|bike|למשפחות|למבוגרים|adventure|טיול|מסלול|מסעדות|פארק|אומגה|חבלים|walk|hike|restaurant|zip|канат|ресторан|парк/u', $name)) {
                $score -= 12;
            }
            if (preg_match('/רכבל/u', $name) && ! str_contains($name, 'בלי רכבל')) {
                $score -= 12;
            }
            $len = mb_strlen($name);
            if ($len > 0 && $len <= 28) {
                $score += 6;
            } elseif ($len <= 42) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $loc->id;
            }
        }

        return $bestId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isOffBlockRow(string $blockKey, array $row): bool
    {
        $blob = mb_strtolower(trim(($row['name'] ?? '').' '.($row['snippet'] ?? '')));

        return $this->offBlockPenalty($blockKey, $blob, $blob) >= 0.15;
    }

    private function noisePenalty(string $content, string $title): float
    {
        $blob = $title.' '.$content;
        if (preg_match('/карта зоны|zone map|מפת אזור|spa |wellness|וולנס|ספא |spa\b|wellness|קניונינג|canyon|пещерный|cave park|аквапарк|waterpark/ui', $blob)) {
            return 0.12;
        }

        return 0.0;
    }

    private function offBlockPenalty(string $blockKey, string $content, string $title): float
    {
        $blob = mb_strtolower($title.' '.$content);
        if ($blockKey === 'garda' && preg_match('/cortina|кортин|brunico|бруник|ברוניקו|tre cime|три пик|3 פסגות|braies|брайес|בראייס|misurina|מיזורינה|ortisei|אורטיזיי/u', $blob)) {
            return 0.18;
        }
        if ($blockKey === 'gardena' && preg_match('/riva del|рива-дель|ריבה דל|лимоне|limone|לימונה|peschiera|cascate di riva|ponale|ledro|לדרו|braies|בראייס|brunico|ברוניקו|tre cime|3 פסגות/u', $blob)) {
            return 0.18;
        }
        if ($blockKey === 'east' && preg_match('/cascate di riva|riva del|рива-дель|ריבה|лимоне|limone|לימונה|tenno|тенно|טנו|varone|וארנה|ledro|לדרו|ponale|פונל|busatte|ortisei|אורטיזיי/u', $blob)) {
            return 0.18;
        }

        return 0.0;
    }

    /**
     * @param  array{needles: list<string>, bases: list<string>, key: string, label: string}  $block
     * @return list<array<string, mixed>>
     */
    private function forceBlockPlaces(array $block): array
    {
        $needles = array_values(array_unique(array_merge($block['needles'] ?? [], $block['bases'] ?? [])));
        $ids = array_values(array_unique(array_merge(
            $this->idsByNeedles($needles),
            $this->overnightIds($block['bases'] ?? []),
        )));
        if ($ids === []) {
            return [];
        }

        $stub = [];
        foreach ($ids as $id) {
            $stub[] = [
                'location_id' => $id,
                'score' => 0.85,
                'snippet' => 'подобрано по названию / ночёвке блока «'.$block['label'].'»',
                'block_type' => 'forced',
                'block' => $block['key'],
                'block_label' => $block['label'],
            ];
        }

        return $stub;
    }

    /**
     * @param  list<string>  $needles
     * @return list<int>
     */
    private function idsByNeedles(array $needles): array
    {
        $needles = array_values(array_filter($needles, fn ($n) => mb_strlen((string) $n) >= 3));
        if ($needles === []) {
            return [];
        }

        $ids = Location::query()
            ->where(function ($q) use ($needles) {
                foreach ($needles as $n) {
                    $like = '%'.$n.'%';
                    $q->orWhere('model', 'like', $like)
                        ->orWhereHas('descriptions', function ($d) use ($like) {
                            $d->where('name', 'like', $like)->orWhere('description', 'like', $like);
                        })
                        ->orWhereHas('facts.descriptions', function ($d) use ($like) {
                            $d->where('name', 'like', $like)->orWhere('text', 'like', $like);
                        });
                }
            })
            ->limit(20)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * @param  list<string>  $bases
     * @return list<int>
     */
    private function overnightIds(array $bases): array
    {
        $q = Location::query()->whereHas('facts', fn ($f) => $f->where('group', 'overnight'));
        if ($bases !== []) {
            $q->where(function ($w) use ($bases) {
                foreach ($bases as $n) {
                    if (mb_strlen((string) $n) < 4) {
                        continue;
                    }
                    $like = '%'.$n.'%';
                    $w->orWhere('model', 'like', $like)
                        ->orWhereHas('descriptions', fn ($d) => $d->where('name', 'like', $like))
                        ->orWhereHas('facts.descriptions', function ($d) use ($like) {
                            $d->where('name', 'like', $like)->orWhere('text', 'like', $like);
                        });
                }
            });
        }

        return $q->limit(8)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<string>  $needles
     */
    private function needleBoost(array $needles, string $content, string $title): float
    {
        $hay = mb_strtolower($title.' '.$content);
        $boost = 0.0;
        foreach ($needles as $n) {
            $n = mb_strtolower(trim((string) $n));
            if (mb_strlen($n) < 4) {
                continue;
            }
            if (mb_strpos($hay, $n) !== false) {
                $boost += 0.05;
            }
        }

        return min(0.25, $boost);
    }

    /**
     * @return array{country: string|null, regions: list<string>, manufacturer_id: int|null, category_ids: list<int>}
     */
    public function parseFilters(string $query): array
    {
        $q = mb_strtolower($query);
        $country = null;
        foreach ((array) config('travel_regions.countries', []) as $code => $row) {
            foreach ($this->aliases($row) as $alias) {
                if ($alias !== '' && mb_strpos($q, $alias) !== false) {
                    $country = (string) $code;
                    break 2;
                }
            }
        }

        $extra = [
            'доломит' => 'IT',
            'dolomit' => 'IT',
            'гарда' => 'IT',
            'garda' => 'IT',
            'верон' => 'IT',
            'венец' => 'IT',
            'venice' => 'IT',
            'verona' => 'IT',
        ];
        if ($country === null) {
            foreach ($extra as $needle => $code) {
                if (mb_strpos($q, $needle) !== false) {
                    $country = $code;
                    break;
                }
            }
        }

        $regionKeys = [];
        $regionMap = (array) config('travel_regions.regions', []);
        $scan = $country ? [$country => $regionMap[$country] ?? []] : $regionMap;
        foreach ($scan as $code => $regions) {
            foreach ((array) $regions as $key => $row) {
                foreach ($this->aliases($row) as $alias) {
                    if ($alias !== '' && mb_strpos($q, $alias) !== false) {
                        $regionKeys[] = $code.':'.$key;
                    }
                }
            }
        }

        if (preg_match('/доломит|dolomit/ui', $query)) {
            $regionKeys[] = 'IT:trentino';
            $regionKeys[] = 'IT:veneto';
        }
        if (preg_match('/гарда|garda/ui', $query)) {
            $regionKeys[] = 'IT:trentino';
            $regionKeys[] = 'IT:veneto';
            $regionKeys[] = 'IT:lombardia';
        }

        $regionKeys = array_values(array_unique($regionKeys));
        $manufacturerId = $country
            ? (int) (Manufacturer::query()->where('code', $country)->value('id') ?? 0)
            : 0;
        $categoryIds = $this->categoryIdsForKeys($regionKeys);

        return [
            'country' => $country,
            'regions' => $regionKeys,
            'manufacturer_id' => $manufacturerId > 0 ? $manufacturerId : null,
            'category_ids' => $categoryIds,
        ];
    }

    /**
     * @param  array{country: string|null, regions: list<string>, manufacturer_id: int|null, category_ids: list<int>}  $filters
     * @return list<int>
     */
    private function sqlLocationIds(array $filters): array
    {
        $q = Location::query()->orderBy('id');
        $used = false;
        if (! empty($filters['manufacturer_id'])) {
            $q->where('manufacturer_id', $filters['manufacturer_id']);
            $used = true;
        }
        if (! empty($filters['category_ids'])) {
            $ids = $filters['category_ids'];
            $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $ids));
            $used = true;
        }

        if (! $used) {
            return Location::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $locationIds
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function loadChunks(string $conn, array $locationIds)
    {
        $q = DB::connection($conn)
            ->table('rag_chunks as c')
            ->leftJoin('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->whereNotNull('c.location_id')
            ->whereNotNull('c.embedding');

        if ($locationIds !== []) {
            $q->whereIn('c.location_id', $locationIds);
        }

        $narrow = count($locationIds) > 120 || $locationIds === [];
        if ($narrow) {
            $q->whereIn('c.block_type', ['header', 'about', 'attraction']);
        }

        return $q->orderBy('c.id')
            ->limit(self::MAX_SCORE_CHUNKS)
            ->select([
                'c.id',
                'c.location_id',
                'c.block_type',
                'c.content_he',
                'c.embedding',
                'd.title',
            ])
            ->get();
    }

    /**
     * @param  list<array{location_id: int, score: float, snippet: string, block_type: string}>  $top
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $top): array
    {
        if ($top === []) {
            return [];
        }
        $ids = [];
        foreach ($top as $row) {
            $id = (int) ($row['location_id'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $lang = Language::admin() ?? Language::getDefault();
        $locations = Location::query()
            ->with(['descriptions', 'manufacturer.descriptions', 'categories.descriptions', 'area.descriptions'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($top as $row) {
            $loc = $locations->get((int) ($row['location_id'] ?? $row['id'] ?? 0));
            if (! $loc) {
                continue;
            }
            $tripRole = $loc->trip_role ?: ($row['role'] ?? 'place');
            $isBase = ! empty($row['is_base']) || $tripRole === 'base';
            $out[] = [
                'id' => $loc->id,
                'model' => $loc->model,
                'name' => $loc->nameFor($lang),
                'country' => $loc->manufacturer?->nameFor($lang),
                'region' => $loc->categories->map(fn ($c) => $c->descriptions->firstWhere('language_id', $lang?->id)?->name
                    ?? $c->descriptions->first()?->name)->filter()->implode(', '),
                'lat' => $loc->lat,
                'lng' => $loc->lng,
                'score' => round($row['score'], 4),
                'snippet' => $row['snippet'],
                'block' => $row['block'] ?? null,
                'block_label' => $row['block_label'] ?? null,
                'role' => $isBase ? 'base' : $tripRole,
                'is_base' => $isBase,
                'area_id' => $loc->area_id,
                'trip_role' => $loc->trip_role,
                'area_name' => $loc->area?->nameFor($lang),
                'area_sort' => $loc->area?->sort_order,
                'area_min_days' => $loc->area?->min_days,
                'area_max_days' => $loc->area?->max_days,
                'date_from' => $row['date_from'] ?? null,
                'date_to' => $row['date_to'] ?? null,
                'edit_url' => route('admin.locations.edit', $loc->id),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function categoryIdsForKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $names = [];
        $regions = (array) config('travel_regions.regions', []);
        foreach ($keys as $pair) {
            [$code, $key] = array_pad(explode(':', $pair, 2), 2, '');
            $row = $regions[$code][$key] ?? null;
            if (! is_array($row)) {
                continue;
            }
            foreach (['he', 'ru'] as $lang) {
                $n = trim((string) ($row[$lang] ?? ''));
                if ($n !== '') {
                    $names[] = $n;
                }
            }
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            return [];
        }

        return Category::query()
            ->whereHas('descriptions', fn ($q) => $q->whereIn('name', $names))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function aliases(array $row): array
    {
        $out = [];
        foreach (['he', 'ru'] as $lang) {
            $out[] = mb_strtolower(trim((string) ($row[$lang] ?? '')));
        }
        foreach ((array) ($row['aliases'] ?? []) as $alias) {
            $out[] = mb_strtolower(trim((string) $alias));
        }

        return array_values(array_filter($out));
    }

    private function keywordBoost(string $query, string $content, string $title): float
    {
        $hay = mb_strtolower($title.' '.$content);
        $boost = 0.0;
        if (preg_match_all('/[\p{L}\p{N}]{3,}/u', $query, $m)) {
            $stop = ['для', 'что', 'это', 'маршрут', 'пожалуйста', 'только', 'дней', 'день'];
            foreach ($m[0] as $token) {
                $t = mb_strtolower($token);
                if (in_array($t, $stop, true) || mb_strlen($t) < 3) {
                    continue;
                }
                if (mb_strpos($hay, $t) !== false) {
                    $boost += 0.03;
                }
            }
        }

        return min(0.18, $boost);
    }

    /**
     * @return list<float>|null
     */
    private function decodeEmbedding(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $values = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $values = is_array($decoded) ? $decoded : null;
        } else {
            return null;
        }
        if (! is_array($values) || $values === []) {
            return null;
        }
        $out = [];
        foreach ($values as $v) {
            if (! is_numeric($v)) {
                return null;
            }
            $out[] = (float) $v;
        }

        return $out;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
