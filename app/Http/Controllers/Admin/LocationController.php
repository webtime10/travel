<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Language;
use App\Models\Location;
use App\Models\LocationDescription;
use App\Models\LocationFact;
use App\Models\LocationFactDescription;
use App\Jobs\SplitLocationFactsJob;
use App\Services\LocationFactChecker;
use App\Services\LocationFactExtractor;
use App\Services\LocationFactsPipelineStore;
use App\Services\LocationRuTranslator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle = 'Locations';
        $defaultLanguage = Language::getDefault();
        $search = trim((string) $request->input('q', ''));
        $factsStatus = (string) $request->input('facts', '');

        $query = Location::query()
            ->select(['id', 'model', 'lat', 'lng', 'sort_order', 'status', 'facts_status', 'facts_coverage', 'facts_split_at', 'created_at', 'updated_at'])
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id), 'categories.descriptions'])
            ->withCount('facts');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('model', $search)
                    ->orWhereHas('descriptions', fn ($d) => $d->where('name', 'like', '%'.$search.'%'));
            });
        }

        if ($factsStatus === 'none') {
            $query->whereNull('facts_split_at');
        } elseif ($factsStatus === 'review') {
            $query->whereIn('facts_status', ['bad', 'warn', 'error']);
        } elseif (array_key_exists($factsStatus, Location::FACTS_STATUSES)) {
            $query->where('facts_status', $factsStatus);
        }

        if (in_array($factsStatus, ['review', 'bad', 'warn'], true)) {
            $query->orderByRaw("FIELD(facts_status, 'error', 'bad', 'warn')")->orderBy('facts_coverage');
        }

        $locations = $query
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $statusCounts = Location::query()
            ->selectRaw("COALESCE(facts_status, IF(facts_split_at IS NULL, 'none', 'unchecked')) as s, COUNT(*) as c")
            ->groupBy('s')
            ->pluck('c', 's');

        return view('admin.locations.index', compact('locations', 'pageTitle', 'defaultLanguage', 'search', 'factsStatus', 'statusCounts'));
    }

    public function create()
    {
        $pageTitle = 'Locations';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $categories = Category::treeForParentSelect($defaultLanguage);

        return view('admin.locations.create', compact('pageTitle', 'languages', 'defaultLanguage', 'categories'));
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')->with('info', 'Сначала добавьте язык');
        }

        $request->validate($this->rules($languages, 'unique:locations,model'));

        DB::transaction(function () use ($request, $languages) {
            $location = Location::create($this->baseAttributes($request));
            $location->categories()->sync($request->input('category_ids', []));
            $this->syncDescriptions($location, $request, $languages);
        });

        return redirect()->route('admin.locations.index')->with('success', 'Локация создана');
    }

    public function edit(Location $location)
    {
        $pageTitle = 'Locations';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $categories = Category::treeForParentSelect($defaultLanguage);
        $location->load(['descriptions', 'categories', 'facts.descriptions']);

        return view('admin.locations.edit', compact('pageTitle', 'location', 'languages', 'defaultLanguage', 'categories'));
    }

    public function update(Request $request, Location $location)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages, Rule::unique('locations', 'model')->ignore($location->id)));

        DB::transaction(function () use ($request, $languages, $location) {
            $location->update($this->baseAttributes($request));
            $location->categories()->sync($request->input('category_ids', []));
            $this->syncDescriptions($location, $request, $languages);
        });

        return redirect()->route('admin.locations.index')->with('success', 'Локация обновлена');
    }

    public function destroy(Location $location)
    {
        $location->delete();

        return redirect()->route('admin.locations.index')->with('success', 'Локация удалена');
    }

    public function bulkDelete(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('selected', [])));
        if ($ids) {
            Location::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.locations.index')->with('success', 'Выбранные локации удалены');
    }

    public function translateRu(Request $request, Location $location, LocationRuTranslator $translator)
    {
        @set_time_limit(600);

        $limit = max(1, min(40, (int) $request->input('limit', 12)));

        try {
            $result = $translator->translateBatch($location, $limit);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = [
            'ok' => empty($result['warning']),
            'translated' => $result['translated'],
            'remaining' => $result['remaining'],
            'total' => $result['total'],
            'already' => $result['already'],
            'done' => $result['done'],
            'translated_ru' => $result['total'] - $result['remaining'],
        ];
        if (! empty($result['warning'])) {
            $payload['message'] = $result['warning'];
        }

        return response()->json($payload, empty($result['warning']) ? 200 : 422);
    }

    public function splitFacts(Location $location, LocationFactExtractor $extractor, LocationFactChecker $checker)
    {
        @set_time_limit(600);

        try {
            $result = $extractor->extract($location);
            $check = $checker->checkAndRepair($location->refresh());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result + ['status' => $check['status'], 'coverage' => $check['coverage'], 'repaired' => $check['repaired']]);
    }

    public function checkFacts(Location $location, LocationFactChecker $checker)
    {
        @set_time_limit(600);

        try {
            $check = $checker->check($location);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $check);
    }

    public function factsPipelineStatus(LocationFactsPipelineStore $pipeline)
    {
        return response()->json($pipeline->status() + [
            'not_split' => Location::whereNull('facts_split_at')->count(),
        ]);
    }

    public function factsPipelineStart(LocationFactsPipelineStore $pipeline)
    {
        if ($pipeline->running()) {
            return response()->json(['ok' => false, 'message' => 'Разбивка уже идёт'], 409);
        }

        $ids = Location::whereNull('facts_split_at')->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            return response()->json(['ok' => true, 'message' => 'Все локации уже разбиты', 'started' => 0]);
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            SplitLocationFactsJob::dispatch($first);
        }

        return response()->json(['ok' => true, 'started' => count($ids), 'message' => 'Разбивка запущена: '.count($ids).' локаций']);
    }

    public function factsPipelineCancel(LocationFactsPipelineStore $pipeline)
    {
        $pipeline->cancel();

        return response()->json(['ok' => true, 'message' => 'Остановлено']);
    }

    public function factStore(Request $request, Location $location)
    {
        $data = $request->validate($this->factRules());
        DB::transaction(function () use ($location, $data) {
            $fact = $location->facts()->create($this->factAttributes($data) + [
                'sort_order' => (int) $location->facts()->max('sort_order') + 1,
            ]);
            $this->saveFactDescription($fact, $data);
        });

        return redirect()->to(route('admin.locations.edit', $location->id).'#facts')->with('success', 'Факт добавлен');
    }

    public function factUpdate(Request $request, Location $location, LocationFact $fact)
    {
        abort_unless($fact->location_id === $location->id, 404);
        $data = $request->validate($this->factRules());
        DB::transaction(function () use ($fact, $data) {
            $fact->update($this->factAttributes($data));
            $this->saveFactDescription($fact, $data);
        });

        return redirect()->to(route('admin.locations.edit', $location->id).'#fact-'.$fact->id)->with('success', 'Факт сохранён');
    }

    public function factDestroy(Location $location, LocationFact $fact)
    {
        abort_unless($fact->location_id === $location->id, 404);
        $fact->delete();

        return redirect()->to(route('admin.locations.edit', $location->id).'#facts')->with('success', 'Факт удалён');
    }

    private function factRules(): array
    {
        return [
            'group' => ['required', Rule::in(array_keys(LocationFact::GROUPS))],
            'type' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'text' => 'nullable|string',
            'hours' => 'nullable|string',
            'address' => 'nullable|string|max:500',
            'map_url' => 'nullable|string',
            'waze_url' => 'nullable|string',
            'website' => 'nullable|string',
            'is_kosher' => 'nullable|boolean',
        ];
    }

    private function factAttributes(array $data): array
    {
        return [
            'group' => $data['group'],
            'address' => $data['address'] ?? null,
            'map_url' => $data['map_url'] ?? null,
            'waze_url' => $data['waze_url'] ?? null,
            'website' => $data['website'] ?? null,
            'is_kosher' => (bool) ($data['is_kosher'] ?? false),
        ];
    }

    private function saveFactDescription(LocationFact $fact, array $data): void
    {
        $he = Language::where('code', 'he')->firstOrFail();
        LocationFactDescription::updateOrCreate(
            ['location_fact_id' => $fact->id, 'language_id' => $he->id],
            [
                'type' => $data['type'] ?? null,
                'name' => $data['name'],
                'text' => $data['text'] ?? null,
                'hours' => $data['hours'] ?? null,
            ]
        );
    }

    private function rules(Collection $languages, $modelUniqueRule): array
    {
        $rules = [
            'model' => ['nullable', 'string', 'max:64', $modelUniqueRule],
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
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

        return $rules;
    }

    private function baseAttributes(Request $request): array
    {
        $model = trim((string) $request->input('model', ''));

        return [
            'model' => $model !== '' ? $model : null,
            'lat' => $request->filled('lat') ? (float) $request->input('lat') : null,
            'lng' => $request->filled('lng') ? (float) $request->input('lng') : null,
            'sort_order' => (int) $request->input('sort_order', 0),
            'status' => $request->boolean('status'),
        ];
    }

    private function syncDescriptions(Location $location, Request $request, Collection $languages): void
    {
        foreach ($languages as $language) {
            $name = trim((string) $request->input('name_'.$language->code, ''));
            $desc = $location->descriptions()->where('language_id', $language->id)->first();
            if ($name === '') {
                $desc?->delete();

                continue;
            }
            $payload = [
                'name' => $name,
                'description' => $request->input('description_'.$language->code),
            ];
            if ($desc) {
                $desc->update($payload);
            } else {
                LocationDescription::create(array_merge($payload, [
                    'location_id' => $location->id,
                    'language_id' => $language->id,
                ]));
            }
        }
    }
}
