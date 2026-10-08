<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;
use Illuminate\Support\Facades\DB;

class DocxLiteralImportService
{
    public function docDirectory(): string
    {
        return base_path('doc');
    }

    /**
     * @return list<string> absolute paths, sorted by basename
     */
    public function sortedDocxFiles(): array
    {
        $files = glob($this->docDirectory().DIRECTORY_SEPARATOR.'*.docx') ?: [];
        usort($files, fn ($a, $b) => strcasecmp(basename($a), basename($b)));

        return $files;
    }

    /**
     * @return list<array{n: int, path: string, source: string, model: string}>
     */
    public function enumerateFiles(): array
    {
        $out = [];
        $n = 0;
        foreach ($this->sortedDocxFiles() as $path) {
            if (filesize($path) < 1000) {
                continue;
            }
            $n++;
            $source = basename($path);
            $out[] = [
                'n' => $n,
                'path' => $path,
                'source' => $source,
                'model' => (string) $n,
            ];
        }

        return $out;
    }

    public function isFileImported(int $n, string $sourceName): bool
    {
        $model = (string) $n;
        $product = Product::query()
            ->select(['id', 'model', 'meta'])
            ->where('model', $model)
            ->first();

        if (! $product) {
            return false;
        }

        $meta = $product->heMeta();
        if ($meta === null) {
            return false;
        }

        $src = (string) ($meta['source_file'] ?? '');

        return $src === $sourceName || $src === '';
    }

    /**
     * Models that already have a product row (treated as imported).
     *
     * @return array<string, true>
     */
    public function importedModelMap(): array
    {
        $map = [];
        foreach (Product::query()->whereNotNull('model')->pluck('model') as $model) {
            $map[(string) $model] = true;
        }

        return $map;
    }

    /**
     * @return list<array{n: int, path: string, source: string, model: string}>
     */
    public function pendingFiles(): array
    {
        $files = $this->enumerateFiles();
        if ($files === []) {
            return [];
        }

        $imported = $this->importedModelMap();

        return array_values(array_filter(
            $files,
            fn (array $row) => ! isset($imported[$row['model']])
        ));
    }

    public function pendingCount(): int
    {
        return count($this->pendingFiles());
    }

    public function ensureLanguagesAndCategory(): Category
    {
        $he = Language::query()->updateOrCreate(
            ['code' => 'he'],
            [
                'name' => 'עברית',
                'locale' => 'he-IL',
                'directory' => 'he-he',
                'sort_order' => 0,
                'status' => true,
                'is_default' => true,
                'is_active' => true,
            ]
        );
        Language::query()->where('code', '!=', 'he')->update(['is_default' => false]);

        $ru = Language::query()->firstOrCreate(
            ['code' => 'ru'],
            [
                'name' => 'Русский',
                'locale' => 'ru-RU',
                'directory' => 'ru-ru',
                'sort_order' => 1,
                'status' => true,
                'is_default' => false,
                'is_active' => true,
            ]
        );
        $en = Language::query()->where('code', 'en')->first();

        $category = Category::query()->find(1);
        if (! $category) {
            $category = Category::query()->create([
                'parent_id' => null,
                'sort_order' => 0,
                'status' => true,
            ]);
        }
        foreach ([$he, $ru] as $lang) {
            DB::table('category_descriptions')->updateOrInsert(
                ['category_id' => $category->id, 'language_id' => $lang->id],
                [
                    'name' => $lang->code === 'he' ? 'מסלולים' : 'Маршруты',
                    'description' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
        try {
            Category::rebuildPaths();
        } catch (\Throwable) {
        }

        return $category;
    }

    /**
     * @return array{ok: bool, product_id?: int, message?: string, model?: string, blocks?: int}
     */
    public function importFile(int $n, string $file, DocxSequentialParser $parser): array
    {
        if (! is_file($file) || filesize($file) < 1000) {
            return ['ok' => false, 'message' => 'File missing or too small'];
        }

        $he = Language::query()->where('code', 'he')->first();
        $ru = Language::query()->where('code', 'ru')->first();
        $en = Language::query()->where('code', 'en')->first();
        if (! $he) {
            return ['ok' => false, 'message' => 'Hebrew language not configured'];
        }

        $category = $this->ensureLanguagesAndCategory();

        $sourceName = basename($file);
        $productName = pathinfo($sourceName, PATHINFO_FILENAME);
        if ($productName === '') {
            $productName = $sourceName;
        }
        $model = (string) $n;

        $publicRel = 'uploads/products/'.$model;
        $destDir = public_path($publicRel);

        try {
            $parsed = $parser->parse($file, $destDir, $publicRel);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'model' => $model];
        }

        $blocks = $parsed['blocks'];
        foreach ($blocks as $i => $b) {
            if (! is_array($b)) {
                continue;
            }
            unset($blocks[$i]['text_ru'], $blocks[$i]['ru_skip']);
        }

        $linkCount = count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'link'));
        $meta = DocxMetaHelper::purifyHebrew([
            'format' => 'docx_sequential_v1',
            'language' => 'he',
            'doc_index' => $n,
            'source_file' => $parsed['source'],
            'blocks' => $blocks,
            'images' => $parsed['images'],
            'stats' => [
                'blocks' => count($blocks),
                'images' => count($parsed['images']),
                'text_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') !== 'image')),
                'image_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'image')),
                'link_blocks' => $linkCount,
            ],
        ]);

        $product = Product::query()->where('model', $model)->first();
        if (! $product) {
            $product = Product::query()->get()->first(
                fn (Product $p) => (int) data_get($p->meta, 'doc_index') === $n
                    || (string) data_get($p->meta, 'source_file') === $sourceName
            );
        }

        if ($product) {
            $product->fill([
                'model' => $model,
                'sort_order' => $n,
                'status' => true,
                'meta' => $meta,
                'meta_ru' => null,
            ])->save();
        } else {
            $product = Product::query()->create([
                'model' => $model,
                'sort_order' => $n,
                'status' => true,
                'meta' => $meta,
                'meta_ru' => null,
            ]);
        }

        foreach ([$he, $ru, $en] as $lang) {
            if (! $lang) {
                continue;
            }
            ProductDescription::query()->updateOrCreate(
                ['product_id' => $product->id, 'language_id' => $lang->id],
                [
                    'name' => $productName,
                    'description' => null,
                ]
            );
        }

        $product->categories()->sync([$category->id]);

        $extractDir = storage_path('app/docx_literal/'.$model);
        if (! is_dir($extractDir)) {
            mkdir($extractDir, 0777, true);
        }
        file_put_contents(
            $extractDir.'/blocks.json',
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return [
            'ok' => true,
            'product_id' => $product->id,
            'model' => $model,
            'blocks' => $meta['stats']['blocks'],
            'source' => $sourceName,
        ];
    }
}
