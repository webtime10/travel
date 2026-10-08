<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Index Hebrew product JSON (all blocks: text/link/image) into PostgreSQL RAG tables.
 */
class RagProductIndexer
{
    public function __construct(
        private GeminiEmbeddingService $embeddings,
        private RagDatabaseService $ragDb,
    ) {}

    /**
     * @return array{chunks: int, document_id: int}
     */
    public function indexProduct(Product $product, bool $reembed = true): array
    {
        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not connected: '.($status['message'] ?? ''));
        }
        if (! ($status['schema_ready'] ?? false)) {
            $this->ragDb->migrateSchema();
        }

        $heMeta = $product->heMeta();
        if ($heMeta === null) {
            throw new RuntimeException('Product #'.$product->id.' has no Hebrew DOCX meta');
        }

        $blocks = $heMeta['blocks'] ?? [];
        if (! is_array($blocks)) {
            $blocks = [];
        }

        $prepared = $this->blocksToChunks($blocks);
        if ($prepared === []) {
            throw new RuntimeException('Product #'.$product->id.' has no indexable blocks');
        }

        $heLang = Language::query()->where('code', 'he')->first();
        $title = '';
        if ($heLang) {
            $title = (string) ($product->descriptions()->where('language_id', $heLang->id)->value('name') ?? '');
        }
        if ($title === '') {
            $title = (string) ($product->descriptions()->value('name') ?? '');
        }

        $conn = RagDatabaseService::CONNECTION;

        $documentId = null;
        DB::connection($conn)->table('rag_documents')->updateOrInsert(
            ['product_id' => $product->id],
            [
                'model' => $product->model,
                'source_file' => (string) ($heMeta['source_file'] ?? ''),
                'language' => 'he',
                'title' => $title !== '' ? $title : null,
                'chunk_count' => 0,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $doc = DB::connection($conn)->table('rag_documents')->where('product_id', $product->id)->first();
        if (! $doc) {
            throw new RuntimeException('Failed to upsert rag_documents');
        }
        $documentId = (int) $doc->id;

        DB::connection($conn)->table('rag_chunks')->where('document_id', $documentId)->delete();

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
                'product_id' => $product->id,
                'model' => $product->model,
                'chunk_index' => $i,
                'block_index' => $chunk['block_index'],
                'block_type' => $chunk['block_type'],
                'content_he' => $chunk['content'],
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
            DB::connection($conn)->table('rag_chunks')->insert($batch);
        }

        DB::connection($conn)->table('rag_documents')->where('id', $documentId)->update([
            'chunk_count' => count($rows),
            'indexed_at' => $now,
            'updated_at' => $now,
        ]);

        return ['chunks' => count($rows), 'document_id' => $documentId];
    }

    /**
     * Convert every sequential block (text/link/image/…) into embeddable chunks.
     *
     * @param  list<mixed>  $blocks
     * @return list<array{block_index: int, block_type: string, content: string, meta: array<string, mixed>}>
     */
    public function blocksToChunks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = strtolower(trim((string) ($block['type'] ?? 'text')));
            if ($type === '') {
                $type = 'text';
            }

            $meta = $block;
            $content = '';

            if ($type === 'image') {
                $src = trim((string) ($block['src'] ?? ''));
                if ($src === '') {
                    continue;
                }
                $content = '[image] '.$src;
            } elseif ($type === 'link') {
                $text = trim((string) ($block['text'] ?? ''));
                $url = trim((string) ($block['url'] ?? ''));
                if ($text === '' && $url === '') {
                    continue;
                }
                $content = trim($text.($url !== '' ? (' URL: '.$url) : ''));
            } else {
                $text = trim((string) ($block['text'] ?? ''));
                $url = trim((string) ($block['url'] ?? ''));
                if ($text === '' && $url === '') {
                    continue;
                }
                $content = $text;
                if ($url !== '' && ! str_contains($content, $url)) {
                    $content = trim($content.' URL: '.$url);
                }
            }

            $content = trim($content);
            if ($content === '') {
                continue;
            }

            // Keep very long blocks but cap for embedding API
            if (mb_strlen($content) > 8000) {
                $content = mb_substr($content, 0, 8000).'…';
            }

            $out[] = [
                'block_index' => (int) $i,
                'block_type' => $type,
                'content' => $content,
                'meta' => $meta,
            ];
        }

        return $out;
    }
}
