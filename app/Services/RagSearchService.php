<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fast RAG search: keyword candidate filter, then cosine on a small embedding set.
 * (Full scan of millions of JSONB vectors is too slow without pgvector.)
 */
class RagSearchService
{
    /** Soft cap: never score more embeddings than this per request. */
    private const MAX_EMBED_SCORE = 2500;

    /** Max products to pull into the candidate set. */
    private const MAX_PRODUCTS = 40;

    /** How many top products to expand into itinerary body text for Gemini. */
    private const MAX_CONTEXT_PRODUCTS = 3;

    /** Soft budget for CONTEXT chars passed to the chat model. */
    private const MAX_CONTEXT_CHARS = 22000;

    /** Min length for itinerary body chunks (keep short day headers like "יום שבת - …"). */
    private const MIN_BODY_CHUNK_LEN = 18;

    public function __construct(
        private GeminiEmbeddingService $embeddings,
        private RagDatabaseService $ragDb,
    ) {}

    /**
     * Detect product model code in free text, e.g. "1975 04-09".
     */
    public function detectModelFilter(string $query): ?string
    {
        if (preg_match('/\bmodel\s*[:=]?\s*([0-9]{3,6}\s+[0-9]{2}-[0-9]{2})\b/iu', $query, $m)) {
            return preg_replace('/\s+/u', ' ', trim($m[1]));
        }
        if (preg_match('/\b([0-9]{3,6}\s+[0-9]{2}-[0-9]{2})\b/u', $query, $m)) {
            return preg_replace('/\s+/u', ' ', trim($m[1]));
        }

        return null;
    }

    /**
     * @return array{
     *   hits: list<array{
     *     id: int,
     *     product_id: int,
     *     model: string|null,
     *     title: string|null,
     *     chunk_index: int,
     *     content_he: string,
     *     score: float
     *   }>,
     *   model_filter: string|null,
     *   scanned: int,
     *   embed_ms: int,
     *   search_ms: int
     * }
     */
    public function search(string $query, int $limit = 8, ?string $modelFilter = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'hits' => [],
                'model_filter' => null,
                'scanned' => 0,
                'embed_ms' => 0,
                'search_ms' => 0,
            ];
        }

        $limit = max(1, min(20, $limit));
        $modelFilter = $modelFilter !== null ? trim($modelFilter) : $this->detectModelFilter($query);
        if ($modelFilter === '') {
            $modelFilter = null;
        }

        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false) || ! ($status['schema_ready'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not ready: '.($status['message'] ?? ''));
        }

        $embedText = mb_substr($query, 0, 4000);
        $t0 = microtime(true);
        $queryVector = $this->embeddings->embedOne($embedText);
        $embedMs = (int) round((microtime(true) - $t0) * 1000);

        $conn = RagDatabaseService::CONNECTION;
        $t1 = microtime(true);

        $productIds = [];
        if ($modelFilter !== null) {
            $productIds = DB::connection($conn)
                ->table('rag_documents')
                ->where('model', $modelFilter)
                ->limit(self::MAX_PRODUCTS)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($productIds === []) {
            $productIds = $this->findCandidateProductIds($conn, $query);
        }

        $rows = $this->loadCandidateChunks($conn, $productIds, $modelFilter);
        $top = [];
        $scanned = 0;

        foreach ($rows as $row) {
            $scanned++;
            $vector = $this->decodeEmbedding($row->embedding);
            if ($vector === null) {
                continue;
            }
            $content = (string) ($row->content_he ?? '');
            $score = $this->cosineSimilarity($queryVector, $vector);
            // Boost chunks whose text overlaps query terms (helps RU query vs HE content a bit via shared Latin names)
            $score += $this->keywordBoost($query, $content, (string) ($row->title ?? ''));
            // Prefer body text over short TOC/title crumbs when ranking products
            $len = mb_strlen($content);
            if ($len < 80) {
                $score -= 0.04;
            }
            if (str_contains($content, '#_Toc')) {
                $score -= 0.06;
            }
            if ($score <= 0) {
                continue;
            }
            $top[] = [
                'id' => (int) $row->id,
                'product_id' => (int) $row->product_id,
                'model' => $row->model !== null ? (string) $row->model : null,
                'title' => $row->title !== null ? (string) $row->title : null,
                'chunk_index' => (int) $row->chunk_index,
                'content_he' => $content,
                'score' => $score,
            ];
        }

        usort($top, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        // Titles match well for place queries, but Gemini needs day-program body text.
        $hits = $this->expandToProductContext($conn, $top, $query);
        $searchMs = (int) round((microtime(true) - $t1) * 1000);

        return [
            'hits' => $hits,
            'model_filter' => $modelFilter,
            'scanned' => $scanned,
            'embed_ms' => $embedMs,
            'search_ms' => $searchMs,
        ];
    }

    /**
     * @param  list<array{product_id?: int, model?: string|null, title?: string|null, content_he?: string, score?: float}>  $hits
     */
    public function buildContext(array $hits): string
    {
        if ($hits === []) {
            return '';
        }

        $parts = [];
        $used = 0;
        foreach ($hits as $i => $hit) {
            if ($used >= self::MAX_CONTEXT_CHARS) {
                break;
            }
            $n = $i + 1;
            $model = trim((string) ($hit['model'] ?? ''));
            $title = trim((string) ($hit['title'] ?? ''));
            $productId = (int) ($hit['product_id'] ?? 0);
            $score = isset($hit['score']) ? round((float) $hit['score'], 4) : null;
            $text = trim((string) ($hit['content_he'] ?? ''));
            if ($text === '') {
                continue;
            }
            $room = self::MAX_CONTEXT_CHARS - $used;
            $text = mb_substr($text, 0, min(4000, $room));

            $header = "[{$n}] product_id={$productId}";
            if ($model !== '') {
                $header .= " model={$model}";
            }
            if ($title !== '') {
                $header .= ' title='.$title;
            }
            if ($score !== null) {
                $header .= " score={$score}";
            }
            $block = $header."\n".$text;
            $parts[] = $block;
            $used += mb_strlen($block) + 8;
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * @param  list<array{product_id?: int, model?: string|null, title?: string|null, score?: float}>  $hits
     * @return list<array{product_id: int, model: string|null, title: string|null, score: float}>
     */
    public function sources(array $hits): array
    {
        $seen = [];
        $out = [];
        foreach ($hits as $hit) {
            $productId = (int) ($hit['product_id'] ?? 0);
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $out[] = [
                'product_id' => $productId,
                'model' => isset($hit['model']) && $hit['model'] !== null ? (string) $hit['model'] : null,
                'title' => isset($hit['title']) && $hit['title'] !== null ? (string) $hit['title'] : null,
                'score' => round((float) ($hit['score'] ?? 0), 4),
            ];
        }

        return $out;
    }

    /**
     * Turn title/TOC vector hits into itinerary body passages for top products.
     *
     * @param  list<array{id?: int, product_id: int, model: string|null, title: string|null, chunk_index?: int, content_he: string, score: float}>  $ranked
     * @return list<array{id: int, product_id: int, model: string|null, title: string|null, chunk_index: int, content_he: string, score: float}>
     */
    private function expandToProductContext(string $conn, array $ranked, string $query): array
    {
        if ($ranked === []) {
            return [];
        }

        $bestByProduct = [];
        foreach ($ranked as $hit) {
            $pid = (int) ($hit['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (! isset($bestByProduct[$pid]) || (float) $hit['score'] > (float) $bestByProduct[$pid]['score']) {
                $bestByProduct[$pid] = $hit;
            }
        }

        uasort($bestByProduct, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $topProducts = array_slice($bestByProduct, 0, self::MAX_CONTEXT_PRODUCTS, true);
        $terms = $this->extractSearchTerms($query);

        $expanded = [];
        $productCount = max(1, count($topProducts));
        $perProductBudget = (int) floor(self::MAX_CONTEXT_CHARS / $productCount);

        foreach ($topProducts as $pid => $seed) {
            $budget = $perProductBudget;
            $passages = $this->loadProductBodyPassages($conn, (int) $pid, $terms, (float) $seed['score'], (string) ($seed['title'] ?? ''));
            if ($passages === []) {
                $text = trim((string) ($seed['content_he'] ?? ''));
                if ($text !== '') {
                    $expanded[] = [
                        'id' => (int) ($seed['id'] ?? 0),
                        'product_id' => (int) $pid,
                        'model' => $seed['model'] ?? null,
                        'title' => $seed['title'] ?? null,
                        'chunk_index' => (int) ($seed['chunk_index'] ?? 0),
                        'content_he' => mb_substr($text, 0, min(2000, $budget)),
                        'score' => (float) $seed['score'],
                    ];
                }

                continue;
            }

            foreach ($passages as $passage) {
                if ($budget < 200) {
                    break;
                }
                $take = min(mb_strlen($passage['content_he']), min(3500, $budget));
                $passage['content_he'] = mb_substr($passage['content_he'], 0, $take);
                $expanded[] = $passage;
                $budget -= $take + 100;
            }
        }

        return $expanded !== [] ? $expanded : array_slice($ranked, 0, 8);
    }

    /**
     * Load day/place body text for a product, merge into readable passages.
     *
     * @param  list<string>  $terms
     * @return list<array{id: int, product_id: int, model: string|null, title: string|null, chunk_index: int, content_he: string, score: float}>
     */
    private function loadProductBodyPassages(
        string $conn,
        int $productId,
        array $terms,
        float $seedScore,
        string $title,
    ): array {
        $rows = DB::connection($conn)
            ->table('rag_chunks as c')
            ->leftJoin('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->where('c.product_id', $productId)
            ->where(function ($w) {
                $w->where('c.block_type', 'text')
                    ->orWhere(function ($w2) {
                        $w2->where('c.block_type', 'link')
                            ->where('c.content_he', 'not ilike', '%#_Toc%');
                    });
            })
            ->whereRaw('char_length(c.content_he) >= ?', [self::MIN_BODY_CHUNK_LEN])
            ->orderBy('c.chunk_index')
            ->select([
                'c.id',
                'c.product_id',
                'c.model',
                'c.chunk_index',
                'c.content_he',
                'd.title',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $list = $rows->values();
        $dayHeaders = [];
        $placeHits = [];
        foreach ($list as $i => $row) {
            $text = trim((string) $row->content_he);
            $hay = mb_strtolower($text);
            // Real itinerary day titles, e.g. "יום שבת - 31/8 - ורונה"
            if (preg_match('/^(יום|יוֹם)\b/u', $text) || preg_match('/\d{1,2}\/\d{1,2}/u', $text) && preg_match('/יום|יוֹם/u', $text)) {
                $dayHeaders[] = $i;
            }
            foreach ($terms as $term) {
                $t = mb_strtolower($term);
                if (mb_strlen($t) >= 3 && mb_strpos($hay, $t) !== false) {
                    $placeHits[] = $i;
                    break;
                }
            }
        }

        // Prefer real day-program sections. FAQ/intro also mentions places — skip it
        // when day headers exist, otherwise CONTEXT fills with tips instead of days.
        if ($dayHeaders !== []) {
            $firstDay = min($dayHeaders);
            $keep = [];
            foreach ($dayHeaders as $mi) {
                $from = max(0, $mi - 1);
                $to = min($list->count() - 1, $mi + 55);
                for ($j = $from; $j <= $to; $j++) {
                    $keep[$j] = true;
                }
            }
            foreach ($placeHits as $mi) {
                if ($mi < $firstDay) {
                    continue;
                }
                $from = max($firstDay, $mi - 1);
                $to = min($list->count() - 1, $mi + 25);
                for ($j = $from; $j <= $to; $j++) {
                    $keep[$j] = true;
                }
            }
            ksort($keep);
            $selected = [];
            foreach (array_keys($keep) as $j) {
                $selected[] = $list[$j];
            }
        } elseif ($placeHits !== []) {
            $keep = [];
            foreach ($placeHits as $mi) {
                $from = max(0, $mi - 1);
                $to = min($list->count() - 1, $mi + 35);
                for ($j = $from; $j <= $to; $j++) {
                    $keep[$j] = true;
                }
            }
            ksort($keep);
            $selected = [];
            foreach (array_keys($keep) as $j) {
                $selected[] = $list[$j];
            }
        } else {
            $selected = $list->filter(fn ($r) => mb_strlen((string) $r->content_he) >= 80)->take(100)->values()->all();
        }

        if ($selected === []) {
            return [];
        }

        $passages = [];
        $buf = null;
        foreach ($selected as $row) {
            $text = trim((string) $row->content_he);
            if ($text === '') {
                continue;
            }
            if ($buf === null) {
                $buf = [
                    'id' => (int) $row->id,
                    'product_id' => (int) $row->product_id,
                    'model' => $row->model !== null ? (string) $row->model : null,
                    'title' => $row->title !== null ? (string) $row->title : ($title !== '' ? $title : null),
                    'chunk_index' => (int) $row->chunk_index,
                    'content_he' => $text,
                    'score' => $seedScore,
                ];

                continue;
            }

            if (mb_strlen($buf['content_he']) < 1400) {
                $buf['content_he'] .= "\n".$text;
            } else {
                $passages[] = $buf;
                $buf = [
                    'id' => (int) $row->id,
                    'product_id' => (int) $row->product_id,
                    'model' => $row->model !== null ? (string) $row->model : null,
                    'title' => $row->title !== null ? (string) $row->title : ($title !== '' ? $title : null),
                    'chunk_index' => (int) $row->chunk_index,
                    'content_he' => $text,
                    'score' => $seedScore,
                ];
            }
        }
        if ($buf !== null) {
            $passages[] = $buf;
        }

        return $passages;
    }

    /**
     * @return list<int>
     */
    private function findCandidateProductIds(string $conn, string $query): array
    {
        $terms = $this->extractSearchTerms($query);
        if ($terms === []) {
            return $this->fallbackRecentProductIds($conn);
        }

        $ids = [];

        // Titles only first (~1k docs) — fast and enough for place/route queries.
        $titleQ = DB::connection($conn)->table('rag_documents')->select('product_id');
        $titleQ->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->orWhere('title', 'ilike', '%'.$this->escapeLike($term).'%');
            }
        });
        foreach ($titleQ->limit(self::MAX_PRODUCTS)->pluck('product_id') as $id) {
            $ids[(int) $id] = true;
        }

        // Optional: short timed text probe on chunks (avoid full-table minute scans).
        if (count($ids) < 5) {
            $placeTerms = array_values(array_filter(
                $terms,
                fn (string $t) => preg_match('/[\x{0590}-\x{05FF}]/u', $t) || preg_match('/^[a-zA-Z]{4,}$/', $t)
            ));
            foreach (array_slice($placeTerms, 0, 3) as $term) {
                try {
                    DB::connection($conn)->unprepared("SET LOCAL statement_timeout = '4000'");
                    $found = DB::connection($conn)->select(
                        'SELECT DISTINCT product_id
                         FROM rag_chunks
                         WHERE embedding IS NOT NULL
                           AND content_he ILIKE ?
                         LIMIT 30',
                        ['%'.$this->escapeLike($term).'%']
                    );
                    foreach ($found as $row) {
                        $ids[(int) $row->product_id] = true;
                    }
                } catch (\Throwable) {
                    // timeout / skip — keep going
                } finally {
                    try {
                        DB::connection($conn)->unprepared('SET LOCAL statement_timeout = 0');
                    } catch (\Throwable) {
                    }
                }
                if (count($ids) >= self::MAX_PRODUCTS) {
                    break;
                }
            }
        }

        if ($ids === []) {
            return $this->fallbackRecentProductIds($conn);
        }

        return array_slice(array_map('intval', array_keys($ids)), 0, self::MAX_PRODUCTS);
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, object>
     */
    private function loadCandidateChunks(string $conn, array $productIds, ?string $modelFilter)
    {
        $q = DB::connection($conn)
            ->table('rag_chunks as c')
            ->leftJoin('rag_documents as d', 'd.id', '=', 'c.document_id')
            ->whereNotNull('c.embedding')
            ->where(function ($w) {
                $w->whereNull('c.block_type')
                    ->orWhereNotIn('c.block_type', ['image', 'footer']);
            })
            ->where('c.content_he', 'not ilike', '%#_Toc%')
            ->whereRaw('char_length(c.content_he) >= 40')
            ->select([
                'c.id',
                'c.product_id',
                'c.model',
                'c.chunk_index',
                'c.content_he',
                'c.embedding',
                'd.title',
            ]);

        if ($modelFilter !== null) {
            $q->where('c.model', $modelFilter)
                ->orderBy('c.chunk_index')
                ->limit(self::MAX_EMBED_SCORE);
        } elseif ($productIds !== []) {
            $q->whereIn('c.product_id', $productIds)
                ->orderBy('c.chunk_index')
                ->limit(self::MAX_EMBED_SCORE);
        } else {
            $q->orderByDesc('c.id')->limit(800);
        }

        return $q->get();
    }

    /**
     * @return list<int>
     */
    private function fallbackRecentProductIds(string $conn): array
    {
        return DB::connection($conn)
            ->table('rag_documents')
            ->orderByDesc('indexed_at')
            ->limit(20)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Find products that contain itinerary ROUTE LINES for plan locations
     * (sense: "on date X we go to places Y"), not bare TOC/title mentions.
     *
     * @param  list<string>  $locations
     * @return list<array{product_id: int, model: string|null, title: string|null, score: float, matched: list<string>, route_lines: list<string>, route_line_count: int}>
     */
    public function findProductsByLocationMentions(array $locations, ?int $maxProducts = null): array
    {
        if ($maxProducts !== null) {
            $maxProducts = max(1, $maxProducts);
        }
        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false) || ! ($status['schema_ready'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not ready: '.($status['message'] ?? ''));
        }

        /** @var array<string, string> $termToLabel */
        $termToLabel = [];
        foreach ($locations as $loc) {
            $loc = trim((string) $loc);
            if ($loc === '' || preg_match('/аэропорт|только\s+прил|только\s+вылет/iu', $loc) === 1) {
                continue;
            }
            foreach ($this->primaryLocationTerms($loc) as $term) {
                if (mb_strlen($term) >= 3) {
                    $termToLabel[$term] = $loc;
                }
            }
        }
        if ($termToLabel === []) {
            return [];
        }

        $conn = RagDatabaseService::CONNECTION;
        $terms = array_keys($termToLabel);
        /**
         * @var array<int, array{
         *   product_id: int,
         *   model: string|null,
         *   title: string|null,
         *   score: float,
         *   matched: array<string, true>,
         *   route_lines: array<string, string>
         * }> $byProduct
         */
        $byProduct = [];

        try {
            DB::connection($conn)->unprepared("SET LOCAL statement_timeout = '120000'");

            $q = DB::connection($conn)->table('rag_chunks as c')
                ->leftJoin('rag_documents as d', 'd.id', '=', 'c.document_id')
                ->select(['c.product_id', 'c.model', 'd.title', 'c.content_he'])
                ->whereRaw('char_length(c.content_he) >= 20')
                ->where('c.content_he', 'not ilike', '%#_Toc%')
                ->where(function ($w) use ($terms) {
                    foreach ($terms as $term) {
                        $w->orWhere('c.content_he', 'ilike', '%'.$this->escapeLike($term).'%');
                    }
                });

            foreach ($q->orderBy('c.product_id')->cursor() as $row) {
                $pid = (int) ($row->product_id ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $content = (string) ($row->content_he ?? '');
                if ($content === '') {
                    continue;
                }
                $extracted = $this->extractRouteLinesWithLocations($content, $termToLabel);
                if ($extracted['lines'] === []) {
                    continue;
                }
                if (! isset($byProduct[$pid])) {
                    $byProduct[$pid] = [
                        'product_id' => $pid,
                        'model' => $row->model !== null ? (string) $row->model : null,
                        'title' => $row->title !== null ? (string) $row->title : null,
                        'score' => 0.0,
                        'matched' => [],
                        'route_lines' => [],
                    ];
                }
                foreach ($extracted['labels'] as $label) {
                    $byProduct[$pid]['matched'][$label] = true;
                }
                foreach ($extracted['lines'] as $line) {
                    if (count($byProduct[$pid]['route_lines']) >= 12) {
                        break;
                    }
                    $key = mb_strtolower(mb_substr($line, 0, 160));
                    if (! isset($byProduct[$pid]['route_lines'][$key])) {
                        $byProduct[$pid]['route_lines'][$key] = $line;
                        $byProduct[$pid]['score'] += 1.0;
                    }
                }
                if ($byProduct[$pid]['title'] === null && $row->title !== null) {
                    $byProduct[$pid]['title'] = (string) $row->title;
                }
                if ($byProduct[$pid]['model'] === null && $row->model !== null) {
                    $byProduct[$pid]['model'] = (string) $row->model;
                }
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Route-line location scan failed: '.$e->getMessage(), 0, $e);
        } finally {
            try {
                DB::connection($conn)->unprepared('SET LOCAL statement_timeout = 0');
            } catch (\Throwable) {
            }
        }

        $out = [];
        foreach ($byProduct as $row) {
            $lines = array_values($row['route_lines']);
            $out[] = [
                'product_id' => $row['product_id'],
                'model' => $row['model'],
                'title' => $row['title'],
                'score' => round((float) $row['score'] + count($row['matched']) * 0.5, 4),
                'matched' => array_keys($row['matched']),
                'route_lines' => array_slice($lines, 0, 12),
                'route_line_count' => count($lines),
            ];
        }
        usort($out, function (array $a, array $b) {
            $ca = (int) ($a['route_line_count'] ?? 0);
            $cb = (int) ($b['route_line_count'] ?? 0);
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }
            $ma = count($a['matched']);
            $mb = count($b['matched']);
            if ($ma !== $mb) {
                return $mb <=> $ma;
            }

            return $b['score'] <=> $a['score'];
        });

        return $maxProducts === null ? $out : array_slice($out, 0, $maxProducts);
    }

    /**
     * Pull itinerary-like lines that mention plan locations.
     * Sense: "on date/day X → places Y" (flexible separators), not bare name drops.
     *
     * @param  array<string, string>  $termToLabel
     * @return array{lines: list<string>, labels: list<string>}
     */
    private function extractRouteLinesWithLocations(string $content, array $termToLabel): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $kept = [];
        $labels = [];
        foreach ($lines as $raw) {
            $line = trim((string) $raw);
            if ($line === '' || mb_strlen($line) < 8 || mb_strlen($line) > 400) {
                continue;
            }
            if (! $this->looksLikeItineraryRouteLine($line)) {
                continue;
            }
            $hay = mb_strtolower($line);
            $lineLabels = $this->labelsMatchedInHaystack($hay, $termToLabel);
            if ($lineLabels === []) {
                continue;
            }
            foreach ($lineLabels as $label) {
                $labels[$label] = true;
            }
            $kept[] = $line;
            if (count($kept) >= 20) {
                break;
            }
        }

        return [
            'lines' => $kept,
            'labels' => array_keys($labels),
        ];
    }

    /**
     * Structural "when → where" line (RU/HE/EN date styles, not TOC junk).
     */
    private function looksLikeItineraryRouteLine(string $line): bool
    {
        if (str_contains($line, '#_Toc')) {
            return false;
        }
        // Skip pure TOC / page numbers
        if (preg_match('/^\d{1,3}\s*$/u', $line) === 1) {
            return false;
        }

        $heWeek = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        $months = 'январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр'
            .'|january|february|march|april|may|june|july|august|september|october|november|december';

        // Date / day prefix then places (14.8 — … / 14,8 – … / 14/08: … / יום …)
        if (preg_match(
            '/^(?:'
            .'(?:\d{1,2}\s*[.\/,]\s*\d{1,2}(?:\s*[.\/,]\s*\d{2,4})?)'
            .'|(?:\d{1,2}\s+(?:'.$months.')\w*)'
            .'|(?:יום\s+(?:'.$heWeek.'|\d{1,2}))'
            .'|(?:day\s+\d{1,2})'
            .'|(?:день\s+\d{1,2})'
            .')\b/iu',
            $line
        ) === 1) {
            // Need some place-like tail after separator or space
            return preg_match('/[-–—:·|]\s*\p{L}.+/u', $line) === 1
                || preg_match('/\d{1,2}\s*[.\/,]\s*\d{1,2}\s+\p{L}.+/u', $line) === 1
                || preg_match('/יום\s+\S+.+\p{L}{3,}/u', $line) === 1;
        }

        // Explicit transfer day between regions
        if (preg_match('/^(?:переезжа|переезд|трансфер|עוברים|מעבר)\b/iu', $line) === 1
            && preg_match('/\p{L}{3,}/u', $line) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function primaryLocationTerms(string $label): array
    {
        $low = mb_strtolower($label);
        $map = [
            'garda' => ['garda', 'גארדה'],
            'гарда' => ['garda', 'גארדה'],
            'рива' => ['riva', 'ריווה'],
            'лимоне' => ['limone', 'לימונה'],
            'тенно' => ['tenno'],
            'вароне' => ['varone'],
            'мольвено' => ['molveno'],
            'тремозине' => ['tremosine'],
            'комо' => ['como', 'קומו'],
            'варенна' => ['varenna'],
            'белладжо' => ['bellagio'],
            'лекко' => ['lecco'],
            'менаджио' => ['menaggio'],
            'доломит' => ['dolomiti', 'dolomites', 'דולומיטים'],
            'трентин' => ['trentino'],
            'ортизеи' => ['ortisei'],
            'товель' => ['tovel'],
            'нардис' => ['nardis'],
        ];
        $terms = [];
        foreach ($map as $needle => $extra) {
            if (mb_strpos($low, $needle) !== false) {
                foreach ($extra as $t) {
                    $terms[$t] = true;
                }
            }
        }

        return array_keys($terms);
    }

    /**
     * @param  array<string, string>  $termToLabel
     * @return list<string>
     */
    private function labelsMatchedInHaystack(string $hay, array $termToLabel): array
    {
        $labels = [];
        foreach ($termToLabel as $term => $label) {
            if (mb_strpos($hay, mb_strtolower($term)) !== false) {
                $labels[$label] = true;
            }
        }

        return array_keys($labels);
    }

    /**
     * Expand RU/EN place names to Hebrew tokens present in DOCX corpus.
     *
     * @return list<string>
     */
    private function extractSearchTerms(string $query): array
    {
        $q = mb_strtolower($query);
        $synonyms = [
            'гарда' => ['גארדה', 'garda'],
            'garda' => ['גארדה', 'garda'],
            'лимоне' => ['limone', 'לימונה'],
            'мольвено' => ['molveno'],
            'тенно' => ['tenno'],
            'вароне' => ['varone'],
            'тремозине' => ['tremosine'],
            'варенна' => ['varenna'],
            'белладжо' => ['bellagio'],
            'лекко' => ['lecco'],
            'менаджио' => ['menaggio'],
            'венеция' => ['ונציה', 'venice'],
            'venice' => ['ונציה', 'venice'],
            'венето' => ['ונטו', 'veneto'],
            'veneto' => ['ונטו', 'veneto'],
            'доломиты' => ['דולומיטים', 'dolomiti', 'dolomites'],
            'dolomites' => ['דולומיטים', 'dolomiti'],
            'dolomiti' => ['דולומיטים', 'dolomiti'],
            'италия' => ['איטליה', 'italy'],
            'italy' => ['איטליה', 'italy'],
            'капри' => ['קאפרי', 'capri'],
            'capri' => ['קאפרי', 'capri'],
            'рим' => ['רומא', 'rome'],
            'rome' => ['רומא', 'rome'],
            'тоскана' => ['טוסקנה', 'tuscany'],
            'tuscany' => ['טוסקנה', 'tuscany'],
            'комо' => ['קומו', 'como'],
            'como' => ['קומו', 'como'],
            'милан' => ['מילאנו', 'milan'],
            'верона' => ['ורונה', 'verona'],
            'verona' => ['ורונה', 'verona'],
            'сирмионе' => ['סירמיונה', 'sirmione'],
            'sirmione' => ['סירמיונה', 'sirmione'],
            'ортизеи' => ['אורטיזיי', 'ortisei'],
            'кортина' => ['קורטינה', 'cortina'],
            'cortina' => ['קורטינה', 'cortina'],
            'маршрут' => ['מסלול'],
            '2024' => ['2024'],
        ];

        $terms = [];
        foreach ($synonyms as $needle => $extra) {
            if (mb_strpos($q, $needle) !== false) {
                foreach ($extra as $t) {
                    $terms[$t] = true;
                }
            }
        }

        // Raw tokens (Hebrew / Latin / digits), skip very short RU stop-ish words
        if (preg_match_all('/[\p{L}\p{N}]{3,}/u', $query, $m)) {
            $stop = ['для', 'что', 'это', 'наш', 'наша', 'базе', 'базы', 'день', 'дней', 'план', 'краткий', 'сделай', 'ответ', 'русском', 'коротко', 'смотрить', 'смотреть'];
            foreach ($m[0] as $token) {
                $low = mb_strtolower($token);
                if (in_array($low, $stop, true)) {
                    continue;
                }
                $terms[$token] = true;
                if (isset($synonyms[$low])) {
                    foreach ($synonyms[$low] as $t) {
                        $terms[$t] = true;
                    }
                }
            }
        }

        return array_values(array_keys($terms));
    }

    private function keywordBoost(string $query, string $content, string $title): float
    {
        $hay = mb_strtolower($title.' '.$content);
        $boost = 0.0;
        foreach ($this->extractSearchTerms($query) as $term) {
            $t = mb_strtolower($term);
            if (mb_strlen($t) < 3) {
                continue;
            }
            if (mb_strpos($hay, $t) !== false) {
                $boost += 0.02;
            }
        }

        return min(0.15, $boost);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
            if (! is_array($decoded)) {
                return null;
            }
            $values = $decoded;
        } else {
            return null;
        }

        $out = [];
        foreach ($values as $v) {
            if (! is_numeric($v)) {
                return null;
            }
            $out[] = (float) $v;
        }

        return $out === [] ? null : $out;
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
