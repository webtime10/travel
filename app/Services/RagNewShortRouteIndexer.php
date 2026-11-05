<?php

namespace App\Services;

use App\Models\NewShortRoute;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Index New Short route days into dedicated PostgreSQL tables nsr_documents / nsr_chunks.
 * Locations are not used — text days only.
 */
class RagNewShortRouteIndexer
{
    public function __construct(
        private GeminiEmbeddingService $embeddings,
        private RagDatabaseService $ragDb,
    ) {}

    /**
     * @return array{chunks: int, document_id: int}
     */
    public function indexRoute(NewShortRoute $route, bool $reembed = true): array
    {
        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not connected: '.($status['message'] ?? ''));
        }
        if (! $this->ragDb->hasNewShortColumns()) {
            $this->ragDb->migrateSchema();
        }

        $prepared = $this->routeToChunks($route);
        if ($prepared === []) {
            throw new RuntimeException('New Short #'.$route->id.' has no day lines to index');
        }

        $title = trim((string) ($route->title_ru ?: $route->title ?: ''));
        $conn = RagDatabaseService::CONNECTION;
        $docCode = $route->doc_code ? (string) $route->doc_code : null;

        DB::connection($conn)->table('nsr_documents')->updateOrInsert(
            ['new_short_route_id' => $route->id],
            [
                'doc_code' => $docCode,
                'source_file' => 'new_short:'.$route->id,
                // Оригинал туров — иврит (RU только для админки).
                'language' => 'he',
                'title' => $title !== '' ? $title : null,
                'days_count' => (int) $route->days_count,
                'chunk_count' => 0,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $doc = DB::connection($conn)->table('nsr_documents')
            ->where('new_short_route_id', $route->id)
            ->first();
        if (! $doc) {
            throw new RuntimeException('Failed to upsert nsr_documents for new_short #'.$route->id);
        }
        $documentId = (int) $doc->id;

        DB::connection($conn)->table('nsr_chunks')->where('document_id', $documentId)->delete();

        $texts = array_column($prepared, 'content');
        $vectors = $reembed ? $this->embeddings->embedMany($texts) : array_fill(0, count($texts), null);

        if ($reembed && count($vectors) !== count($prepared)) {
            throw new RuntimeException('Embedding count mismatch');
        }

        $now = now();
        $rows = [];
        foreach ($prepared as $i => $chunk) {
            $embedding = $vectors[$i] ?? null;
            $rows[] = [
                'document_id' => $documentId,
                'new_short_route_id' => $route->id,
                'doc_code' => $docCode,
                'chunk_index' => $i,
                'block_index' => $chunk['block_index'],
                'block_type' => $chunk['block_type'],
                'day_index' => $chunk['day_index'] ?? null,
                'content' => $chunk['content'],
                'content_tokens' => max(1, (int) ceil(mb_strlen($chunk['content']) / 4)),
                'embedding' => $embedding !== null
                    ? json_encode($embedding, JSON_UNESCAPED_UNICODE)
                    : null,
                'embedding_model' => $embedding !== null ? $this->embeddings->model() : null,
                'embedding_dim' => $embedding !== null ? $this->embeddings->dimensions() : null,
                'meta' => json_encode($chunk['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DB::connection($conn)->table('nsr_chunks')->insert($batch);
        }

        DB::connection($conn)->table('nsr_documents')->where('id', $documentId)->update([
            'chunk_count' => count($rows),
            'indexed_at' => $now,
            'updated_at' => $now,
        ]);

        return ['chunks' => count($rows), 'document_id' => $documentId];
    }

    /**
     * @return list<array{block_index: int, block_type: string, content: string, day_index: ?int, meta: array<string, mixed>}>
     */
    public function routeToChunks(NewShortRoute $route): array
    {
        $out = [];
        $block = 0;

        $titleHe = trim((string) ($route->title ?? ''));
        $titleRu = trim((string) ($route->title_ru ?? ''));
        // Вектор / поиск — на иврите (оригинал). RU только в meta для админки.
        $headerParts = [];
        if ($route->doc_code) {
            $headerParts[] = 'קוד '.$route->doc_code;
        }
        if ($titleHe !== '') {
            $headerParts[] = $titleHe;
        } elseif ($titleRu !== '') {
            $headerParts[] = $titleRu;
        }
        $headerParts[] = $route->days_count.' ימים';
        $header = implode(' · ', $headerParts);
        if ($header !== '') {
            $out[] = [
                'block_index' => $block++,
                'block_type' => 'header',
                'day_index' => null,
                'content' => $this->cap($header),
                'meta' => [
                    'new_short_route_id' => $route->id,
                    'doc_code' => $route->doc_code,
                    'group' => 'header',
                    'days_count' => $route->days_count,
                    'title_he' => $titleHe !== '' ? $titleHe : null,
                    'title_ru' => $titleRu !== '' ? $titleRu : null,
                    'lang' => 'he',
                ],
            ];
        }

        $heLines = $this->dayLines((string) ($route->route_text ?? ''));
        $ruLines = $this->dayLines((string) ($route->route_text_ru ?? ''));
        $n = max(count($heLines), count($ruLines));

        for ($i = 0; $i < $n; $i++) {
            $he = $heLines[$i] ?? '';
            $ru = $ruLines[$i] ?? '';
            if ($he === '' && $ru === '') {
                continue;
            }

            // Эмбеддинг только по HE; если HE пуст — fallback на RU.
            $content = $he !== '' ? $he : $ru;

            $out[] = [
                'block_index' => $block++,
                'block_type' => 'day',
                'day_index' => $i + 1,
                'content' => $this->cap($content),
                'meta' => [
                    'new_short_route_id' => $route->id,
                    'doc_code' => $route->doc_code,
                    'group' => 'day',
                    'day_index' => $i + 1,
                    'line_he' => $he !== '' ? $he : null,
                    'line_ru' => $ru !== '' ? $ru : null,
                    'title' => $titleHe !== '' ? $titleHe : $titleRu,
                    'lang' => $he !== '' ? 'he' : 'ru',
                ],
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function dayLines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $t = trim($line);
            if ($t !== '') {
                $lines[] = $t;
            }
        }

        return $lines;
    }

    private function cap(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) > 8000) {
            return mb_substr($text, 0, 8000).'…';
        }

        return $text;
    }
}
