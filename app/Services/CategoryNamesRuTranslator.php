<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use Throwable;

class CategoryNamesRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * Translate category names HE → RU (stored in category_descriptions).
     *
     * @return array{
     *   translated: int,
     *   remaining: int,
     *   total: int,
     *   already: int,
     *   done: bool,
     *   warning?: string
     * }
     */
    public function translateBatch(int $limit = 15): array
    {
        $he = Language::query()->where('code', 'he')->where('is_active', true)->first();
        $ru = Language::query()->where('code', 'ru')->where('is_active', true)->first();

        if (! $he || ! $ru) {
            throw new \RuntimeException('Нужны активные языки he и ru');
        }

        $categories = Category::query()
            ->with(['descriptions' => fn ($q) => $q->whereIn('language_id', [$he->id, $ru->id])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $total = 0;
        $already = 0;
        foreach ($categories as $category) {
            $heName = trim((string) ($category->descriptions->firstWhere('language_id', $he->id)?->name ?? ''));
            if ($heName === '') {
                continue;
            }
            $total++;
            $ruName = trim((string) ($category->descriptions->firstWhere('language_id', $ru->id)?->name ?? ''));
            if ($ruName !== '') {
                $already++;
            }
        }

        $pending = [];
        foreach ($categories as $category) {
            $heDesc = $category->descriptions->firstWhere('language_id', $he->id);
            $heName = trim((string) ($heDesc?->name ?? ''));
            if ($heName === '') {
                continue;
            }
            $ruDesc = $category->descriptions->firstWhere('language_id', $ru->id);
            if (trim((string) ($ruDesc?->name ?? '')) !== '') {
                continue;
            }
            $pending[$category->id] = $heName;
            if (count($pending) >= $limit) {
                break;
            }
        }

        if ($pending === []) {
            return [
                'translated' => 0,
                'remaining' => max(0, $total - $already),
                'total' => $total,
                'already' => $already,
                'done' => $already >= $total && $total > 0,
            ];
        }

        $translated = 0;
        $lastError = null;

        try {
            $map = $this->gemini->translateMap($pending);
        } catch (Throwable $e) {
            if (count($pending) <= 1) {
                throw $e;
            }
            $map = [];
            foreach (array_chunk($pending, max(1, (int) ceil(count($pending) / 2)), true) as $chunk) {
                try {
                    $part = $this->gemini->translateMap($chunk);
                    foreach ($part as $id => $ru) {
                        $map[$id] = $ru;
                    }
                } catch (Throwable $inner) {
                    $lastError = $inner->getMessage();
                }
            }
            if ($map === []) {
                throw new \RuntimeException($lastError ?: $e->getMessage());
            }
        }

        foreach ($map as $id => $ruText) {
            $categoryId = (int) $id;
            $ruText = trim((string) $ruText);
            if ($categoryId <= 0 || $ruText === '') {
                continue;
            }

            $desc = CategoryDescription::query()
                ->where('category_id', $categoryId)
                ->where('language_id', $ru->id)
                ->first();

            if ($desc) {
                $desc->update(['name' => $ruText]);
            } else {
                CategoryDescription::create([
                    'category_id' => $categoryId,
                    'language_id' => $ru->id,
                    'name' => $ruText,
                    'description' => null,
                ]);
            }
            $translated++;
        }

        $alreadyAfter = $already + $translated;
        $remaining = max(0, $total - $alreadyAfter);

        $out = [
            'translated' => $translated,
            'remaining' => $remaining,
            'total' => $total,
            'already' => $alreadyAfter,
            'done' => $remaining === 0 && $total > 0,
        ];

        if ($translated === 0 && $remaining > 0) {
            $out['warning'] = $lastError
                ?: 'Gemini не вернул перевод — попробуйте ещё раз.';
        }

        return $out;
    }
}
