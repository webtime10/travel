<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IndexLocationJob;
use App\Jobs\SplitLocationFactsJob;
use App\Jobs\TranslateLocationContentRuJob;
use App\Jobs\TranslateLocationNameRuJob;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Language;
use App\Models\Location;
use App\Models\LocationAttribute;
use App\Models\LocationDescription;
use App\Models\LocationFact;
use App\Models\LocationFactDescription;
use App\Models\Manufacturer;
use App\Services\LocationAdminSearchService;
use App\Services\LocationFactChecker;
use App\Services\LocationFactExtractor;
use App\Services\LocationFactRuTranslator;
use App\Services\LocationFactsPipelineStore;
use App\Services\LocationRuPipelineStore;
use App\Services\LocationRuTranslator;
use App\Services\LocationVectorPipelineStore;
use App\Services\RagDatabaseService;
use App\Services\RagLocationIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request, LocationAdminSearchService $searchService)
    {
        $pageTitle = 'Locations';
        $defaultLanguage = Language::admin();
        $search = trim((string) $request->input('q', ''));
        $semantic = $request->boolean('semantic');
        $factsStatus = (string) $request->input('facts', '');
        $manufacturerId = (int) $request->input('manufacturer_id', 0);
        $categoryId = (int) $request->input('category_id', 0);
        $attributeId = (int) $request->input('attribute_id', 0);
        $areaId = (int) $request->input('area_id', 0);
        $searchNote = null;
        $searchMode = null;
        $orderedSearchIds = [];

        // Drop region/attribute from another country when manufacturer filter is set.
        if ($manufacturerId > 0 && $categoryId > 0) {
            $allowedRegionIds = $this->regionFilterOptions(Language::admin(), $manufacturerId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! in_array($categoryId, $allowedRegionIds, true)) {
                $categoryId = 0;
            }
        }
        if ($manufacturerId > 0 && $attributeId > 0) {
            $allowedAttrIds = $this->attributeFilterOptions($manufacturerId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! in_array($attributeId, $allowedAttrIds, true)) {
                $attributeId = 0;
            }
        }

        $query = Location::query()
            ->select(['locations.id', 'locations.model', 'locations.manufacturer_id', 'locations.area_id', 'locations.lat', 'locations.lng', 'locations.sort_order', 'locations.weight', 'locations.visit_hours', 'locations.kosher_food', 'locations.status', 'locations.facts_status', 'locations.facts_coverage', 'locations.facts_split_at', 'locations.created_at', 'locations.updated_at'])
            ->with([
                'descriptions',
                'categories.descriptions',
                'manufacturer.descriptions',
                'area.descriptions',
            ])
            ->withCount('facts');

        if ($search !== '') {
            $tokens = $searchService->tokens($search);
            if ($tokens === [] && ! preg_match('/^\d+$/', $search)) {
                $searchNote = 'Введите минимум 2 символа для поиска';
                $query->whereRaw('1 = 0');
            } elseif ($semantic) {
                $sem = $searchService->semanticIds($search, 40);
                $likeIds = $searchService->likeIds($search, $tokens, 40);
                $orderedSearchIds = array_values(array_unique(array_merge($sem['ids'], $likeIds)));
                if ($sem['error']) {
                    $searchNote = 'Gemini: '.$sem['error'];
                    if ($likeIds !== []) {
                        $orderedSearchIds = $likeIds;
                        $searchNote .= ' · показан обычный поиск';
                    }
                } elseif ($orderedSearchIds === []) {
                    $searchNote = 'По смыслу ничего не найдено';
                } else {
                    $searchMode = 'gemini';
                    $searchNote = 'По смыслу (Gemini): '.count($sem['ids']).' · буквы: '.count($likeIds);
                }
                if ($orderedSearchIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('locations.id', $orderedSearchIds);
                }
            } else {
                $orderedSearchIds = $searchService->likeIds($search, $tokens, 200);
                $searchMode = 'like';
                if ($orderedSearchIds === []) {
                    $searchNote = 'По буквам пусто — нажмите «По смыслу (Gemini)»';
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('locations.id', $orderedSearchIds);
                }
            }
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

        if ($manufacturerId > 0) {
            $query->where('manufacturer_id', $manufacturerId);
        }
        if ($categoryId > 0) {
            $category = Category::find($categoryId);
            $categoryIds = $category
                ? array_merge([$category->id], $category->descendantIdList())
                : [$categoryId];
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
        }
        if ($attributeId > 0) {
            $query->whereHas('attributeValues', fn ($q) => $q->where('attribute_id', $attributeId));
        }
        if ($areaId > 0) {
            $query->where('area_id', $areaId);
        } elseif ($request->input('area_id') === 'none') {
            $query->whereNull('area_id');
        }

        if ($orderedSearchIds !== []) {
            $idList = implode(',', array_map('intval', $orderedSearchIds));
            $query->orderByRaw('FIELD(locations.id, '.$idList.')');
        } else {
            $query->orderBy('sort_order')->orderByDesc('id');
        }

        $locations = $query
            ->paginate(50)
            ->withQueryString();

        $vectorIndexed = [];
        $vectorIndexedCount = 0;
        try {
            $ragDb = app(RagDatabaseService::class);
            $vectorIndexed = array_fill_keys(
                $ragDb->indexedLocationIds($locations->pluck('id')->all()),
                true
            );
            $vectorIndexedCount = $ragDb->indexedLocationCount();
        } catch (\Throwable) {
        }

        $statusCounts = Location::query()
            ->selectRaw("COALESCE(facts_status, IF(facts_split_at IS NULL, 'none', 'unchecked')) as s, COUNT(*) as c")
            ->groupBy('s')
            ->pluck('c', 's');

        $manufacturers = Manufacturer::with('descriptions')->withCount('locations')->orderBy('sort_order')->get();
        $regionCategories = $this->regionFilterOptions($defaultLanguage, $manufacturerId);
        $filterAttributes = $this->attributeFilterOptions($manufacturerId);
        $filterAreas = Area::with('descriptions')
            ->withCount(['locations' => function ($q) use ($manufacturerId) {
                if ($manufacturerId > 0) {
                    $q->where('locations.manufacturer_id', $manufacturerId);
                }
            }])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($manufacturerId > 0) {
            $filterAreas = $filterAreas->filter(fn (Area $a) => (int) $a->locations_count > 0)->values();
        }
        $areaNoneCount = Location::query()
            ->when($manufacturerId > 0, fn ($q) => $q->where('manufacturer_id', $manufacturerId))
            ->whereNull('area_id')
            ->count();
        $areaFilterValue = $request->input('area_id', '');

        $ruNamePending = $this->pendingRuNameCount();
        $ruContentPending = $this->pendingRuContentCount();

        return view('admin.locations.index', compact(
            'locations',
            'pageTitle',
            'defaultLanguage',
            'search',
            'semantic',
            'searchNote',
            'searchMode',
            'factsStatus',
            'statusCounts',
            'manufacturers',
            'manufacturerId',
            'regionCategories',
            'categoryId',
            'filterAttributes',
            'attributeId',
            'filterAreas',
            'areaId',
            'areaFilterValue',
            'areaNoneCount',
            'ruNamePending',
            'ruContentPending',
            'vectorIndexed',
            'vectorIndexedCount',
        ));
    }

    public function suggest(Request $request, LocationAdminSearchService $searchService): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        return response()->json([
            'q' => $q,
            'items' => $searchService->suggest($q, 15),
        ]);
    }

    /**
     * @return Collection<int, array{id: int, label: string, count: int}>
     */
    private function regionFilterOptions(?Language $lang, int $manufacturerId = 0): Collection
    {
        $parentNames = array_filter([
            (string) config('travel_regions.region_parent.ru'),
            (string) config('travel_regions.region_parent.he'),
        ]);
        $parent = Category::query()
            ->with('descriptions')
            ->whereHas('descriptions', fn ($q) => $q->whereIn('name', $parentNames))
            ->first();
        if (! $parent) {
            return Category::treeForParentSelect($lang);
        }

        $allowedNames = null;
        if ($manufacturerId > 0) {
            $code = Manufacturer::query()->where('id', $manufacturerId)->value('code');
            $cfg = (array) data_get(config('travel_regions.regions'), $code, []);
            $allowedNames = [];
            foreach ($cfg as $row) {
                foreach (['ru', 'he'] as $k) {
                    $n = trim((string) ($row[$k] ?? ''));
                    if ($n !== '') {
                        $allowedNames[] = $n;
                    }
                }
            }
            $allowedNames = array_values(array_unique($allowedNames));
        }

        $children = $parent->children()
            ->with('descriptions')
            ->withCount(['locations' => function ($q) use ($manufacturerId) {
                if ($manufacturerId > 0) {
                    $q->where('locations.manufacturer_id', $manufacturerId);
                }
            }])
            ->orderBy('sort_order')
            ->get();

        if (is_array($allowedNames)) {
            $children = $children->filter(function (Category $child) use ($allowedNames) {
                foreach ($child->descriptions as $d) {
                    if (in_array((string) $d->name, $allowedNames, true)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $rows = collect();
        foreach ($children as $child) {
            $name = $child->descriptions->firstWhere('language_id', $lang?->id)?->name
                ?? $child->descriptions->first()?->name
                ?? '#'.$child->id;
            $rows->push([
                'id' => $child->id,
                'label' => $name,
                'count' => $child->locations_count,
            ]);
        }

        return $rows;
    }

    /**
     * Attributes used by locations of the selected country (or all if none).
     *
     * @return Collection<int, Attribute>
     */
    private function attributeFilterOptions(int $manufacturerId = 0): Collection
    {
        $query = Attribute::query()
            ->with(['descriptions', 'group.descriptions'])
            ->orderBy('attribute_group_id')
            ->orderBy('sort_order');

        if ($manufacturerId > 0) {
            $query->whereHas('locationValues.location', fn ($q) => $q->where('manufacturer_id', $manufacturerId));
        }

        return $query->get();
    }

    public function create()
    {
        $pageTitle = 'Locations';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::admin();
        $categories = Category::treeForParentSelect($defaultLanguage);
        $manufacturers = Manufacturer::with('descriptions')->orderBy('sort_order')->get();
        $areas = Area::with('descriptions')->where('status', true)->orderBy('sort_order')->orderBy('id')->get();
        $attributeGroups = AttributeGroup::with(['descriptions', 'attributes.descriptions'])->orderBy('sort_order')->get();

        return view('admin.locations.create', compact('pageTitle', 'languages', 'defaultLanguage', 'categories', 'manufacturers', 'areas', 'attributeGroups'));
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
            $this->syncAttributes($location, $request, $languages);
        });

        return redirect()->route('admin.locations.index')->with('success', 'Локация создана');
    }

    public function edit(Location $location)
    {
        $pageTitle = 'Locations';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::admin();
        $categories = Category::treeForParentSelect($defaultLanguage);
        $manufacturers = Manufacturer::with('descriptions')->orderBy('sort_order')->get();
        $areas = Area::with('descriptions')->orderBy('sort_order')->orderBy('id')->get();
        $attributeGroups = AttributeGroup::with(['descriptions', 'attributes.descriptions'])->orderBy('sort_order')->get();
        $location->load(['descriptions', 'categories', 'facts.descriptions', 'attributeValues', 'area']);
        $vectorIndexed = false;
        try {
            $vectorIndexed = in_array(
                $location->id,
                app(RagDatabaseService::class)->indexedLocationIds([$location->id]),
                true
            );
        } catch (\Throwable) {
        }

        return view('admin.locations.edit', compact('pageTitle', 'location', 'languages', 'defaultLanguage', 'categories', 'manufacturers', 'areas', 'attributeGroups', 'vectorIndexed'));
    }

    public function update(Request $request, Location $location)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages, Rule::unique('locations', 'model')->ignore($location->id)));

        DB::transaction(function () use ($request, $languages, $location) {
            $location->update($this->baseAttributes($request));
            $location->categories()->sync($request->input('category_ids', []));
            $this->syncDescriptions($location, $request, $languages);
            $this->syncAttributes($location, $request, $languages);
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

    public function translateFactsRu(Location $location, LocationFactRuTranslator $translator)
    {
        @set_time_limit(600);

        try {
            $result = $translator->translate($location);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result);
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

    public function vectorPipelineStatus(LocationVectorPipelineStore $pipeline, RagDatabaseService $ragDb)
    {
        $all = Location::query()->count();
        $indexed = 0;
        try {
            $indexed = $ragDb->indexedLocationCount();
        } catch (\Throwable) {
        }

        return response()->json($pipeline->status() + [
            'location_total' => $all,
            'indexed' => $indexed,
            'pending' => max(0, $all - $indexed),
        ]);
    }

    public function vectorPipelineStart(LocationVectorPipelineStore $pipeline, RagDatabaseService $ragDb)
    {
        if ($pipeline->running()) {
            return response()->json(['ok' => false, 'message' => 'Вектор уже считается'], 409);
        }

        $ragStatus = $ragDb->connectionStatus();
        if (! ($ragStatus['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'PostgreSQL RAG не подключен'], 503);
        }
        if (! ($ragStatus['schema_ready'] ?? false)) {
            $ragDb->migrateSchema();
        }

        $ids = $this->pendingVectorLocationIds($ragDb);
        if ($ids === []) {
            return response()->json(['ok' => true, 'started' => 0, 'message' => 'Все локации уже в векторе']);
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            IndexLocationJob::dispatch($first);
        }

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'message' => 'Вектор запущен: '.count($ids).' локаций. Нужен queue:work-forever',
        ]);
    }

    public function vectorPipelineCancel(LocationVectorPipelineStore $pipeline)
    {
        $pipeline->cancel();

        return response()->json(['ok' => true, 'message' => 'Остановлено']);
    }

    public function ruNamesPipelineStatus()
    {
        $pipeline = LocationRuPipelineStore::names();

        return response()->json($pipeline->status() + [
            'need' => $this->pendingRuNameCount(),
        ]);
    }

    public function ruNamesPipelineStart()
    {
        $pipeline = LocationRuPipelineStore::names();
        if ($pipeline->running()) {
            return response()->json(['ok' => false, 'message' => 'Перевод названий уже идёт'], 409);
        }

        $ids = $this->pendingRuNameIds();
        if ($ids === []) {
            return response()->json(['ok' => true, 'started' => 0, 'message' => 'Все названия уже на русском']);
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            TranslateLocationNameRuJob::dispatch($first);
        }

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'message' => 'Перевод названий: '.count($ids).' локаций. Нужен queue:work',
        ]);
    }

    public function ruNamesPipelineCancel()
    {
        LocationRuPipelineStore::names()->cancel();

        return response()->json(['ok' => true, 'message' => 'Остановлено']);
    }

    public function ruContentPipelineStatus()
    {
        $pipeline = LocationRuPipelineStore::content();

        return response()->json($pipeline->status() + [
            'need' => $this->pendingRuContentCount(),
        ]);
    }

    public function ruContentPipelineStart()
    {
        $pipeline = LocationRuPipelineStore::content();
        if ($pipeline->running()) {
            return response()->json(['ok' => false, 'message' => 'Перевод содержимого уже идёт'], 409);
        }

        $ids = $this->pendingRuContentIds();
        if ($ids === []) {
            return response()->json(['ok' => true, 'started' => 0, 'message' => 'Всё содержимое уже на русском']);
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            TranslateLocationContentRuJob::dispatch($first);
        }

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'message' => 'Перевод содержимого: '.count($ids).' локаций. Нужен queue:work',
        ]);
    }

    public function ruContentPipelineCancel()
    {
        LocationRuPipelineStore::content()->cancel();

        return response()->json(['ok' => true, 'message' => 'Остановлено']);
    }

    public function indexVector(Location $location, RagLocationIndexer $indexer)
    {
        @set_time_limit(600);

        try {
            $result = $indexer->indexLocation($location);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result);
    }

    /**
     * @return list<int>
     */
    private function pendingVectorLocationIds(RagDatabaseService $ragDb): array
    {
        $ids = Location::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $indexed = array_fill_keys($ragDb->indexedLocationIds($ids), true);

        return array_values(array_filter($ids, fn (int $id) => ! isset($indexed[$id])));
    }

    private function pendingRuNameCount(): int
    {
        return count($this->pendingRuNameIds());
    }

    private function pendingRuContentCount(): int
    {
        return count($this->pendingRuContentIds());
    }

    /**
     * @return list<int>
     */
    private function pendingRuNameIds(): array
    {
        $translator = app(LocationRuTranslator::class);
        $ids = [];
        Location::query()->with('descriptions')->orderBy('id')->chunkById(100, function ($chunk) use ($translator, &$ids) {
            foreach ($chunk as $location) {
                if ($translator->needsNameRu($location)) {
                    $ids[] = (int) $location->id;
                }
            }
        });

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function pendingRuContentIds(): array
    {
        $translator = app(LocationRuTranslator::class);
        $ids = [];
        Location::query()->with(['descriptions', 'facts.descriptions'])->orderBy('id')->chunkById(50, function ($chunk) use ($translator, &$ids) {
            foreach ($chunk as $location) {
                if ($translator->needsContentRu($location) || $this->needsFactsRu($location)) {
                    $ids[] = (int) $location->id;
                }
            }
        });

        return $ids;
    }

    private function needsFactsRu(Location $location): bool
    {
        $he = Language::where('code', 'he')->first();
        $ru = Language::where('code', 'ru')->first();
        if (! $he || ! $ru || $location->facts->isEmpty()) {
            return false;
        }
        foreach ($location->facts as $fact) {
            $heD = $fact->descriptions->firstWhere('language_id', $he->id);
            if (! $heD) {
                continue;
            }
            $ruD = $fact->descriptions->firstWhere('language_id', $ru->id);
            if (! $ruD) {
                return true;
            }
            foreach (['name', 'text', 'hours', 'type'] as $field) {
                $src = trim((string) ($heD->{$field} ?? ''));
                $dst = trim((string) ($ruD->{$field} ?? ''));
                if ($src !== '' && ($dst === '' || LocationRuTranslator::looksHebrew($dst))) {
                    return true;
                }
            }
        }

        return false;
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
            'type_ru' => 'nullable|string|max:255',
            'name_ru' => 'nullable|string|max:255',
            'text_ru' => 'nullable|string',
            'hours_ru' => 'nullable|string',
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

        $ru = Language::where('code', 'ru')->first();
        $ruName = trim((string) ($data['name_ru'] ?? ''));
        if ($ru && $ruName !== '') {
            LocationFactDescription::updateOrCreate(
                ['location_fact_id' => $fact->id, 'language_id' => $ru->id],
                [
                    'type' => $data['type_ru'] ?? null,
                    'name' => $ruName,
                    'text' => $data['text_ru'] ?? null,
                    'hours' => $data['hours_ru'] ?? null,
                ]
            );
        }
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
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'area_id' => 'nullable|exists:areas,id',
            'trip_role' => 'nullable|in:base,place,end',
            'location_attributes' => 'nullable|array',
            'location_attributes.*.attribute_id' => 'nullable|exists:attributes,id',
            'location_attributes.*.text_he' => 'nullable|string',
            'location_attributes.*.text_ru' => 'nullable|string',
            'flexible_blocks' => 'nullable|array',
            'flexible_blocks.*.type' => 'required|string|in:'.implode(',', array_keys(Location::FLEXIBLE_LAYOUTS)),
            'flexible_blocks.*.data' => 'nullable|array',
            'flexible_blocks.*.data.content' => 'nullable|string',
            'flexible_blocks.*.data.label' => 'nullable|string|max:255',
            'flexible_blocks.*.data.url' => 'nullable|string|max:2048',
            'flexible_blocks.*.data.caption' => 'nullable|string|max:500',
            'flexible_blocks.*.data.title' => 'nullable|string|max:255',
            'flexible_blocks.*.data.text' => 'nullable|string',
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

        $tripRole = trim((string) $request->input('trip_role', ''));

        return [
            'model' => $model !== '' ? $model : null,
            'manufacturer_id' => $request->filled('manufacturer_id') ? (int) $request->input('manufacturer_id') : null,
            'area_id' => $request->filled('area_id') ? (int) $request->input('area_id') : null,
            'trip_role' => $tripRole !== '' ? $tripRole : null,
            'lat' => $request->filled('lat') ? (float) $request->input('lat') : null,
            'lng' => $request->filled('lng') ? (float) $request->input('lng') : null,
            'sort_order' => (int) $request->input('sort_order', 0),
            'status' => $request->boolean('status'),
            'flexible_blocks' => $this->normalizeFlexibleBlocks($request),
        ];
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function normalizeFlexibleBlocks(Request $request): array
    {
        $allowed = array_keys(Location::FLEXIBLE_LAYOUTS);
        $out = [];
        foreach (array_values((array) $request->input('flexible_blocks', [])) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if (! in_array($type, $allowed, true)) {
                continue;
            }
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $clean = match ($type) {
                'text' => [
                    'content' => (string) ($data['content'] ?? ''),
                ],
                'link' => [
                    'label' => trim((string) ($data['label'] ?? '')),
                    'url' => trim((string) ($data['url'] ?? '')),
                ],
                'photo', 'map_screenshot' => [
                    'url' => trim((string) ($data['url'] ?? '')),
                    'caption' => trim((string) ($data['caption'] ?? '')),
                ],
                'ad' => [
                    'title' => trim((string) ($data['title'] ?? '')),
                    'text' => (string) ($data['text'] ?? ''),
                    'url' => trim((string) ($data['url'] ?? '')),
                ],
                default => [],
            };
            $out[] = ['type' => $type, 'data' => $clean];
        }

        return $out;
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

    private function syncAttributes(Location $location, Request $request, Collection $languages): void
    {
        $he = $languages->firstWhere('code', 'he') ?? $languages->first();
        $ru = $languages->firstWhere('code', 'ru');
        $location->attributeValues()->delete();
        foreach ((array) $request->input('location_attributes', []) as $row) {
            $attributeId = (int) ($row['attribute_id'] ?? 0);
            if ($attributeId <= 0) {
                continue;
            }
            foreach ([['lang' => $he, 'key' => 'text_he'], ['lang' => $ru, 'key' => 'text_ru']] as $pack) {
                if (! $pack['lang']) {
                    continue;
                }
                $text = trim((string) ($row[$pack['key']] ?? ''));
                if ($text === '') {
                    continue;
                }
                LocationAttribute::create([
                    'location_id' => $location->id,
                    'attribute_id' => $attributeId,
                    'language_id' => $pack['lang']->id,
                    'text' => $text,
                ]);
            }
        }
    }
}
