<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Throwable;

class LocationAdminSearchService
{
    public function __construct(private GeminiJsonClient $gemini) {}

    /**
     * Tokens for LIKE search (each at least 2 chars). Exact model match kept separate.
     *
     * @return list<string>
     */
    public function tokens(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $parts = preg_split('/[\s,;|\/]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p) >= 2) {
                $out[] = $p;
            }
        }

        if ($out === [] && mb_strlen($search) >= 2) {
            $out[] = $search;
        }

        return array_values(array_unique($out));
    }

    /**
     * Fast name/model match. Returns location ids (best effort order).
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public function likeIds(string $search, array $tokens, int $limit = 80): array
    {
        if ($tokens === [] && $search === '') {
            return [];
        }

        $ids = Location::query()
            ->where(function ($q) use ($search, $tokens) {
                if ($search !== '' && preg_match('/^\d+$/', $search)) {
                    $q->orWhere('model', $search)->orWhere('id', (int) $search);
                }
                if ($search !== '') {
                    $q->orWhere('model', 'like', $search.'%')
                        ->orWhere('model', 'like', '%'.$search.'%');
                }
                foreach ($tokens as $token) {
                    $q->orWhereHas('descriptions', function ($d) use ($token) {
                        $d->where('name', 'like', $token.'%')
                            ->orWhere('name', 'like', '%'.$token.'%');
                    });
                }
            })
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique($ids));
    }

    /**
     * Live suggest from 2+ characters.
     *
     * @return list<array{id:int, model:?string, name:string}>
     */
    public function suggest(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $tokens = $this->tokens($q);
        $ids = $this->likeIds($q, $tokens, $limit);
        if ($ids === []) {
            return [];
        }

        $lang = Language::admin();
        $locations = Location::query()
            ->with('descriptions')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $loc = $locations->get($id);
            if (! $loc) {
                continue;
            }
            $out[] = [
                'id' => (int) $loc->id,
                'model' => $loc->model,
                'name' => $loc->nameFor($lang),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Gemini: pick location ids by meaning (transliteration, synonyms, ferry/place phrases).
     *
     * @return array{ids: list<int>, error: ?string}
     */
    public function semanticIds(string $query, int $limit = 40): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['ids' => [], 'error' => 'Введите минимум 2 символа'];
        }

        $catalog = $this->catalogLines();
        if ($catalog === []) {
            return ['ids' => [], 'error' => 'Каталог локаций пуст'];
        }

        $material = "QUERY:\n".$query."\n\nCATALOG (id | model | names):\n".implode("\n", $catalog);

        try {
            $decoded = $this->gemini->ask(
                'You match a travel admin search query to location catalog rows.
Return JSON only: {"ids":[int,...]} — up to '.$limit.' best matches by meaning.
Match transliterations (Bellagio≈Белладжо), Hebrew/Russian/Italian names, ferry/route phrases pointing to a place.
Only use ids from CATALOG. Prefer precise place matches over vague ones. If nothing fits, {"ids":[]}.',
                $material,
                'low',
                2
            );
        } catch (Throwable $e) {
            return ['ids' => [], 'error' => $e->getMessage()];
        }

        $rawIds = $decoded['ids'] ?? [];
        if (! is_array($rawIds)) {
            return ['ids' => [], 'error' => 'Gemini: неожиданный ответ'];
        }

        $allowed = array_fill_keys(
            Location::query()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            true
        );

        $ids = [];
        foreach ($rawIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($allowed[$id]) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= $limit) {
                break;
            }
        }

        return ['ids' => $ids, 'error' => null];
    }

    /**
     * @return list<string>
     */
    private function catalogLines(): array
    {
        $rows = DB::table('locations as l')
            ->leftJoin('location_descriptions as d', 'd.location_id', '=', 'l.id')
            ->select([
                'l.id',
                'l.model',
                DB::raw('GROUP_CONCAT(DISTINCT d.name ORDER BY d.language_id SEPARATOR " / ") as names'),
            ])
            ->groupBy('l.id', 'l.model')
            ->orderBy('l.id')
            ->get();

        $lines = [];
        foreach ($rows as $row) {
            $names = trim((string) ($row->names ?? ''));
            if ($names === '') {
                $names = '—';
            }
            $lines[] = ((int) $row->id).' | '.((string) ($row->model ?? '')).' | '.$names;
        }

        return $lines;
    }
}
