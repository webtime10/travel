<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Location;
use App\Models\LocationFact;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Index one catalog location (title + facts) into PostgreSQL RAG.
 */
class RagLocationIndexer
{
    /** @var array<string, int|null> */
    private array $langIds = [];

    public function __construct(
        private GeminiEmbeddingService $embeddings,
        private RagDatabaseService $ragDb,
    ) {}

    private function langId(string $code): ?int
    {
        if (! array_key_exists($code, $this->langIds)) {
            $id = Language::query()->where('code', $code)->value('id');
            $this->langIds[$code] = $id !== null ? (int) $id : null;
        }

        return $this->langIds[$code];
    }

    /**
     * @return array{chunks: int, document_id: int}
     */
    public function indexLocation(Location $location, bool $reembed = true): array
    {
        $status = $this->ragDb->connectionStatus();
        if (! ($status['ok'] ?? false)) {
            throw new RuntimeException('PostgreSQL RAG not connected: '.($status['message'] ?? ''));
        }
        if (! ($status['schema_ready'] ?? false)) {
            $this->ragDb->migrateSchema();
        }

        $location->loadMissing([
            'descriptions',
            'manufacturer.descriptions',
            'categories.descriptions',
            'facts.descriptions',
        ]);

        $prepared = $this->locationToChunks($location);
        if ($prepared === []) {
            throw new RuntimeException('Location #'.$location->id.' has no indexable text');
        }

        $title = $this->title($location);
        $conn = RagDatabaseService::CONNECTION;

        DB::connection($conn)->table('rag_documents')->updateOrInsert(
            ['location_id' => $location->id],
            [
                'product_id' => null,
                'model' => $location->model,
                'source_file' => 'location:'.$location->id,
                'language' => 'he',
                'title' => $title !== '' ? $title : null,
                'chunk_count' => 0,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $doc = DB::connection($conn)->table('rag_documents')->where('location_id', $location->id)->first();
        if (! $doc) {
            throw new RuntimeException('Failed to upsert rag_documents for location #'.$location->id);
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
                'location_id' => $location->id,
                'product_id' => null,
                'model' => $location->model,
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
     * @return list<array{block_index: int, block_type: string, content: string, meta: array<string, mixed>}>
     */
    public function locationToChunks(Location $location): array
    {
        $out = [];
        $header = $this->headerText($location);
        if ($header !== '') {
            $out[] = [
                'block_index' => 0,
                'block_type' => 'header',
                'content' => $this->cap($header),
                'meta' => [
                    'location_id' => $location->id,
                    'group' => 'header',
                    'manufacturer_id' => $location->manufacturer_id,
                    'category_ids' => $location->categories->pluck('id')->all(),
                ],
            ];
        }

        foreach ($location->facts as $i => $fact) {
            $text = $this->factText($fact);
            if ($text === '') {
                continue;
            }
            $group = (string) ($fact->group ?: 'other');
            $out[] = [
                'block_index' => $i + 1,
                'block_type' => $group,
                'content' => $this->cap($text),
                'meta' => [
                    'location_id' => $location->id,
                    'fact_id' => $fact->id,
                    'group' => $group,
                ],
            ];
        }

        return $out;
    }

    private function headerText(Location $location): string
    {
        $he = $this->nameByCode($location, 'he');
        $ru = $this->nameByCode($location, 'ru');
        $title = $he !== '' ? $he : ($ru !== '' ? $ru : ($location->model ?: 'Location #'.$location->id));

        $parts = ['Название: '.$title];
        if ($he !== '' && $ru !== '' && $he !== $ru) {
            $parts[] = 'RU: '.$ru;
        }
        if ($location->model) {
            $parts[] = 'Model: '.$location->model;
        }

        $ruLang = $this->langId('ru')
            ? Language::query()->find($this->langId('ru'))
            : null;
        $country = $location->manufacturer?->nameFor($ruLang)
            ?: $location->manufacturer?->nameFor()
            ?: '';
        if ($country !== '') {
            $parts[] = 'Страна: '.$country;
        }

        $regions = $location->categories->map(function ($c) use ($ruLang) {
            $d = $ruLang
                ? $c->descriptions->firstWhere('language_id', $ruLang->id)
                : null;

            return optional($d ?? $c->descriptions->first())->name;
        })->filter()->unique()->values();
        if ($regions->isNotEmpty()) {
            $parts[] = 'Регион: '.$regions->implode(', ');
        }

        if ($location->lat !== null && $location->lng !== null) {
            $parts[] = 'Координаты: '.$location->lat.', '.$location->lng;
        }

        return implode('. ', $parts);
    }

    private function factText(LocationFact $fact): string
    {
        $label = LocationFact::GROUPS[$fact->group] ?? ($fact->group ?: 'Прочее');
        $he = $this->factDesc($fact, 'he');
        $ru = $this->factDesc($fact, 'ru');

        $lines = [$label];
        $heBlock = $this->descBlock($he);
        $ruBlock = $this->descBlock($ru);
        if ($heBlock !== '') {
            $lines[] = $heBlock;
        }
        if ($ruBlock !== '' && $ruBlock !== $heBlock) {
            $lines[] = 'RU: '.$ruBlock;
        }

        foreach ([
            'Адрес' => $fact->address,
            'Карта' => $fact->map_url,
            'Waze' => $fact->waze_url,
            'Сайт' => $fact->website,
        ] as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $lines[] = $k.': '.$v;
            }
        }

        $body = trim(implode("\n", array_filter($lines, fn ($l) => trim((string) $l) !== '')));

        return $body === $label ? '' : $body;
    }

    /**
     * @return array{type: string, name: string, text: string, hours: string}
     */
    private function factDesc(LocationFact $fact, string $code): array
    {
        $langId = $this->langId($code);
        $d = $langId
            ? $fact->descriptions->firstWhere('language_id', $langId)
            : null;
        if ($d === null && $code === 'he') {
            $d = $fact->descriptions->first();
        }

        return [
            'type' => trim((string) ($d->type ?? '')),
            'name' => trim((string) ($d->name ?? '')),
            'text' => trim((string) ($d->text ?? '')),
            'hours' => trim((string) ($d->hours ?? '')),
        ];
    }

    /**
     * @param  array{type: string, name: string, text: string, hours: string}  $d
     */
    private function descBlock(array $d): string
    {
        $bits = [];
        if ($d['type'] !== '') {
            $bits[] = $d['type'];
        }
        if ($d['name'] !== '') {
            $bits[] = $d['name'];
        }
        if ($d['text'] !== '') {
            $bits[] = $d['text'];
        }
        if ($d['hours'] !== '') {
            $bits[] = 'Часы: '.$d['hours'];
        }

        return trim(implode('. ', $bits));
    }

    private function title(Location $location): string
    {
        $he = $this->nameByCode($location, 'he');

        return $he !== '' ? $he : (string) ($location->descriptions->first()?->name ?? $location->model ?? '');
    }

    private function nameByCode(Location $location, string $code): string
    {
        $langId = $this->langId($code);
        $d = $langId
            ? $location->descriptions->firstWhere('language_id', $langId)
            : null;

        return trim((string) ($d->name ?? ''));
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
