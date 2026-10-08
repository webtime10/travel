<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;

class ProductBulkRuTranslator
{
    public function __construct(
        private GeminiTranslator $gemini,
        private DocxBlocksRuTranslator $blocksTranslator,
    ) {}

    /**
     * @return list<int> product ids needing RU work
     */
    public function productIdsNeedingTranslation(): array
    {
        $he = Language::query()->where('code', 'he')->where('is_active', true)->first();
        $ru = Language::query()->where('code', 'ru')->where('is_active', true)->first();
        if (! $he || ! $ru) {
            return [];
        }

        $ids = [];
        Product::query()
            ->select(['id', 'model', 'meta', 'meta_ru'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($he, $ru, &$ids) {
                /** @var Product $product */
                foreach ($products as $product) {
                    if ($this->productNeedsTranslation($product, $he->id, $ru->id)) {
                        $ids[] = $product->id;
                    }
                }
            });

        return $ids;
    }

    public function estimateBlockTotal(): int
    {
        $sum = 0;
        Product::query()->select(['id', 'meta'])->chunkById(200, function ($products) use (&$sum) {
            foreach ($products as $product) {
                $heMeta = $product->heMeta();
                if ($heMeta === null) {
                    continue;
                }
                $blocks = $heMeta['blocks'] ?? [];
                $sum += DocxMetaHelper::countTranslatable(is_array($blocks) ? $blocks : []);
            }
        });

        return $sum;
    }

    public function productNeedsTranslation(Product $product, int $heLangId, int $ruLangId): bool
    {
        $heMeta = $product->heMeta();
        if ($heMeta === null) {
            return false;
        }

        if ($this->needsNameTranslation($product, $heLangId, $ruLangId)) {
            return true;
        }

        $heBlocks = $heMeta['blocks'] ?? [];
        if (! is_array($heBlocks)) {
            $heBlocks = [];
        }
        $ruBlocks = is_array($product->meta_ru) ? ($product->meta_ru['blocks'] ?? []) : [];
        if (! is_array($ruBlocks)) {
            $ruBlocks = [];
        }
        $total = DocxMetaHelper::countTranslatable($heBlocks);
        $done = DocxMetaHelper::countTranslated($heBlocks, $ruBlocks);

        return $done < $total;
    }

    public function needsNameTranslation(Product $product, int $heLangId, int $ruLangId): bool
    {
        $heName = trim((string) ($product->descriptions()->where('language_id', $heLangId)->value('name') ?? ''));
        if ($heName === '') {
            return false;
        }
        $ruDesc = $product->descriptions()->where('language_id', $ruLangId)->first();
        $ruName = trim((string) ($ruDesc?->name ?? ''));
        if ($ruName === '') {
            return true;
        }

        return $ruName === $heName;
    }

    /**
     * Translate product title HE→RU if still identical.
     */
    public function translateProductName(Product $product): void
    {
        $he = Language::query()->where('code', 'he')->first();
        $ru = Language::query()->where('code', 'ru')->first();
        if (! $he || ! $ru) {
            return;
        }

        if (! $this->needsNameTranslation($product, $he->id, $ru->id)) {
            return;
        }

        $heName = trim((string) $product->descriptions()->where('language_id', $he->id)->value('name'));
        if ($heName === '') {
            return;
        }

        $map = $this->gemini->translateMap(['name' => $heName]);
        $ruName = trim((string) ($map['name'] ?? ''));
        if ($ruName === '') {
            return;
        }

        ProductDescription::query()->updateOrCreate(
            ['product_id' => $product->id, 'language_id' => $ru->id],
            [
                'name' => $ruName,
                'description' => $product->descriptions()->where('language_id', $ru->id)->value('description'),
            ]
        );
    }

    /**
     * One Gemini batch for meta_ru (+ optional name first call handled separately).
     *
     * @return array{done: bool, blocks_done: int, blocks_total: int, warning?: string}
     */
    public function translateProductBatch(Product $product, int $limit = 12): array
    {
        $product->loadMissing('descriptions');
        $this->translateProductName($product);
        $product->refresh();

        $heMeta = $product->heMeta();
        if ($heMeta === null) {
            return ['done' => true, 'blocks_done' => 0, 'blocks_total' => 0];
        }

        $heBlocks = $heMeta['blocks'] ?? [];
        if (! is_array($heBlocks)) {
            $heBlocks = [];
        }
        $blocksTotal = DocxMetaHelper::countTranslatable($heBlocks);

        $result = $this->blocksTranslator->translateBatch($heMeta, $product->meta_ru, $limit);

        $product->meta = $result['he_meta'];
        $product->meta_ru = $result['ru_meta'];
        $product->save();

        $docIndex = (string) (data_get($result['he_meta'], 'doc_index') ?: $product->model ?: '0');
        if ($docIndex !== '' && $docIndex !== '0') {
            $extractDir = storage_path('app/docx_literal/'.$docIndex);
            if (! is_dir($extractDir)) {
                mkdir($extractDir, 0777, true);
            }
            file_put_contents(
                $extractDir.'/blocks.json',
                json_encode($result['he_meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            file_put_contents(
                $extractDir.'/blocks_ru.json',
                json_encode($result['ru_meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        $blocksDone = (int) data_get($result['ru_meta'], 'stats.translated', $result['already'] ?? 0);

        $payload = [
            'done' => (bool) $result['done'],
            'blocks_done' => $blocksDone,
            'blocks_total' => (int) ($result['total'] ?: $blocksTotal),
        ];
        if (! empty($result['warning'])) {
            $payload['warning'] = $result['warning'];
        }

        return $payload;
    }
}
