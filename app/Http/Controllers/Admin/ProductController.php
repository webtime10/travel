<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Services\DocxBlocksRuTranslator;
use App\Services\DocxJsonCoverageAuditor;
use App\Services\DocxMetaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle = 'Products';
        $defaultLanguage = Language::getDefault();
        $modelsFilter = $this->parseModelsFilter((string) $request->input('models', ''));

        $query = Product::query()
            ->select(['id', 'model', 'sort_order', 'status', 'created_at', 'updated_at'])
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id), 'categories.descriptions']);

        if ($modelsFilter !== []) {
            $query->where(function ($q) use ($modelsFilter) {
                foreach ($modelsFilter as $model) {
                    $q->orWhere('model', $model);
                }
            });
        }

        $products = $query
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Heavy pending/translate counts are loaded via pipeline status AJAX — not on page render.
        $pendingDocx = 0;
        $needsTranslation = 0;
        $modelsInput = implode(', ', $modelsFilter);

        return view('admin.products.index', compact(
            'products',
            'pageTitle',
            'defaultLanguage',
            'pendingDocx',
            'needsTranslation',
            'modelsInput',
            'modelsFilter',
        ));
    }

    /**
     * Parse "33" or "33,4,18" into a list of model codes.
     *
     * @return list<string>
     */
    private function parseModelsFilter(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $out[$part] = true;
        }

        return array_keys($out);
    }

    public function create()
    {
        $pageTitle = 'Products';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $categories = Category::treeForParentSelect($defaultLanguage);

        return view('admin.products.create', compact('pageTitle', 'languages', 'defaultLanguage', 'categories'));
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')->with('info', 'Сначала добавьте язык');
        }

        $rules = [
            'model' => 'nullable|string|max:64|unique:products,model',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
        ];
        foreach ($languages as $language) {
            $c = $language->code;
            $rules['name_'.$c] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['description_'.$c] = 'nullable|string';
        }
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages) {
            $model = trim((string) $request->input('model', ''));
            $product = Product::create([
                'model' => $model !== '' ? $model : null,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            $product->categories()->sync($request->input('category_ids', []));

            foreach ($languages as $language) {
                $name = trim((string) $request->input('name_'.$language->code, ''));
                if ($name === '') {
                    continue;
                }
                ProductDescription::create([
                    'product_id' => $product->id,
                    'language_id' => $language->id,
                    'name' => $name,
                    'description' => $request->input('description_'.$language->code),
                ]);
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Товар создан');
    }

    public function edit(Product $product)
    {
        $pageTitle = 'Products';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $categories = Category::treeForParentSelect($defaultLanguage);
        $product->load(['descriptions', 'categories']);

        return view('admin.products.edit', compact('pageTitle', 'product', 'languages', 'defaultLanguage', 'categories'));
    }

    public function update(Request $request, Product $product)
    {
        $languages = Language::forAdminForms();
        $rules = [
            'model' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'model')->ignore($product->id),
            ],
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
        ];
        foreach ($languages as $language) {
            $c = $language->code;
            $rules['name_'.$c] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['description_'.$c] = 'nullable|string';
        }
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $product) {
            $model = trim((string) $request->input('model', ''));
            $product->update([
                'model' => $model !== '' ? $model : null,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);
            $product->categories()->sync($request->input('category_ids', []));

            foreach ($languages as $language) {
                $name = trim((string) $request->input('name_'.$language->code, ''));
                $desc = $product->descriptions()->where('language_id', $language->id)->first();
                if ($name === '') {
                    if ($desc) {
                        $desc->delete();
                    }

                    continue;
                }
                $payload = [
                    'name' => $name,
                    'description' => $request->input('description_'.$language->code),
                ];
                if ($desc) {
                    $desc->update($payload);
                } else {
                    ProductDescription::create(array_merge($payload, [
                        'product_id' => $product->id,
                        'language_id' => $language->id,
                    ]));
                }
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Товар обновлён');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Товар удалён');
    }

    public function bulkDelete(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('selected', [])));
        if ($ids) {
            Product::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.products.index')->with('success', 'Выбранные товары удалены');
    }

    public function docxJson(Request $request, Product $product)
    {
        $lang = strtolower((string) $request->query('lang', 'he'));
        $isRu = $lang === 'ru';

        if ($isRu) {
            $meta = $product->ruMeta();
            if ($meta === null) {
                return response()->json(['ok' => false, 'message' => 'Нет русского JSON — нажмите A文'], 422);
            }

            return response()->json($meta, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $meta = $product->heMeta();
        if ($meta === null) {
            return response()->json(['ok' => false, 'message' => 'Нет DOCX sequential данных'], 422);
        }

        $meta = DocxMetaHelper::purifyHebrew($meta);

        return response()->json($meta, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function docxBlocks(Request $request, Product $product)
    {
        $lang = strtolower((string) $request->query('lang', 'he'));
        $isRu = $lang === 'ru';

        $meta = $isRu ? $product->ruMeta() : $product->heMeta();
        if ($meta === null) {
            return response()->json([
                'ok' => false,
                'message' => $isRu ? 'Нет русского JSON — нажмите A文' : 'Нет DOCX sequential данных',
            ], 422);
        }

        if (! $isRu) {
            $meta = DocxMetaHelper::purifyHebrew($meta);
        }

        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            $blocks = [];
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = max(1, min(300, (int) $request->query('limit', 200)));
        $total = count($blocks);
        $slice = array_slice($blocks, $offset, $limit, true);

        $items = [];
        foreach ($slice as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                $src = (string) ($block['src'] ?? '');
                $items[] = [
                    'index' => (int) $i + 1,
                    'type' => 'image',
                    'style' => null,
                    'text' => null,
                    'src' => $src !== '' ? asset($src) : null,
                    'src_raw' => $src,
                ];

                continue;
            }

            $text = (string) ($block['text'] ?? '');

            $items[] = [
                'index' => (int) $i + 1,
                'type' => $type !== '' ? $type : 'text',
                'style' => $block['style'] ?? null,
                'text' => $text,
                'url' => ! empty($block['url']) ? (string) $block['url'] : null,
                'empty_ru' => $isRu && trim($text) === '',
                'src' => null,
                'src_raw' => null,
            ];
        }

        $nextOffset = $offset + $limit;

        return response()->json([
            'ok' => true,
            'lang' => $isRu ? 'ru' : 'he',
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'returned' => count($items),
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < $total,
            'remaining' => max(0, $total - $nextOffset),
            'items' => $items,
        ]);
    }

    public function verifyDocx(Product $product, DocxJsonCoverageAuditor $auditor)
    {
        @set_time_limit(300);

        $meta = $product->heMeta();
        if ($meta === null) {
            return response()->json(['ok' => false, 'message' => 'Нет DOCX sequential данных'], 422);
        }
        $meta = DocxMetaHelper::purifyHebrew($meta);

        $path = $auditor->resolveDocxPath($meta);
        if ($path === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Исходный DOCX не найден в папке doc/ (source: '.($meta['source_file'] ?? '—').')',
            ], 404);
        }

        try {
            $report = $auditor->audit($path, $meta);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        $meta['coverage_report'] = [
            'at' => now()->toIso8601String(),
            'coverage_percent' => $report['coverage_percent'],
            'missing_count' => $report['missing_count'],
            'verdict' => $report['verdict'],
            'word_text_units' => $report['word_text_units'],
            'json_text_blocks' => $report['json_text_blocks'],
            'word_images' => $report['word_images'],
            'json_image_blocks' => $report['json_image_blocks'],
        ];
        $product->meta = $meta;
        $product->save();

        return response()->json($report);
    }

    public function translateRu(Request $request, Product $product, DocxBlocksRuTranslator $translator)
    {
        @set_time_limit(600);

        $heMeta = $product->heMeta();
        if ($heMeta === null) {
            return response()->json(['ok' => false, 'message' => 'Нет DOCX sequential данных'], 422);
        }

        $limit = max(1, min(40, (int) $request->input('limit', 12)));

        try {
            $result = $translator->translateBatch($heMeta, $product->meta_ru, $limit);
        } catch (\Throwable $e) {
            $heBlocks = $heMeta['blocks'] ?? [];
            $ruBlocks = is_array($product->meta_ru) ? ($product->meta_ru['blocks'] ?? []) : [];
            $total = DocxMetaHelper::countTranslatable(is_array($heBlocks) ? $heBlocks : []);
            $already = DocxMetaHelper::countTranslated(
                is_array($heBlocks) ? $heBlocks : [],
                is_array($ruBlocks) ? $ruBlocks : []
            );

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'already' => $already,
                'total' => $total,
                'remaining' => max(0, $total - $already),
                'translated_ru' => $already,
            ], 500);
        }

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

        $payload = [
            'ok' => empty($result['warning']),
            'translated' => $result['translated'],
            'remaining' => $result['remaining'],
            'total' => $result['total'],
            'already' => $result['already'],
            'done' => $result['done'],
            'translated_ru' => data_get($result['ru_meta'], 'stats.translated', 0),
        ];

        if (! empty($result['warning'])) {
            $payload['message'] = $result['warning'];
        }

        return response()->json($payload, empty($result['warning']) ? 200 : 422);
    }
}
