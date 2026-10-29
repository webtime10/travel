<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GoldenDay;
use App\Models\GoldenDayStop;
use App\Models\GoldenLabel;
use App\Models\Language;
use App\Models\Location;
use App\Services\GoldenDayBuilder;
use App\Services\GoldenDayLabelSync;
use App\Services\GoldenDayRuTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class GoldenDayController extends Controller
{
    public function index(Request $request, GoldenDayRuTranslator $translator): View
    {
        $q = trim((string) $request->input('q', ''));
        $categoryId = (int) $request->input('category_id', 0);
        $country = strtoupper(trim((string) $request->input('country', 'UA')));
        if (! in_array($country, ['UA', 'IT', 'ALL'], true)) {
            $country = 'UA';
        }

        $query = GoldenDay::query()
            ->with(['stops.location.descriptions', 'area.descriptions', 'category.descriptions', 'labels.descriptions'])
            ->withCount('stops')
            ->orderByDesc('usage_count')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%'.$q.'%')
                    ->orWhere('title_ru', 'like', '%'.$q.'%')
                    ->orWhere('start_point', 'like', '%'.$q.'%')
                    ->orWhere('end_point', 'like', '%'.$q.'%')
                    ->orWhere('start_point_ru', 'like', '%'.$q.'%')
                    ->orWhere('end_point_ru', 'like', '%'.$q.'%')
                    ->orWhere('search_blob', 'like', '%'.$q.'%');
            });
        }

        $regionOptions = $this->regionOptionsForCountry($country === 'ALL' ? null : $country);
        $allowedRegionIds = $regionOptions->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($country === 'UA') {
            $query->where('signature', 'like', 'ua-gd-%');
        } elseif ($country === 'IT') {
            $query->where(function ($w) {
                $w->whereNull('signature')
                    ->orWhere('signature', 'not like', 'ua-gd-%');
            });
        }

        if ($categoryId > 0) {
            if ($allowedRegionIds !== [] && ! in_array($categoryId, $allowedRegionIds, true)) {
                $categoryId = 0;
            } else {
                $query->where('category_id', $categoryId);
            }
        }

        $days = $query->paginate(50)->withQueryString();

        return view('admin.golden_days.index', [
            'pageTitle' => 'Golden days · '.($country === 'ALL' ? 'All' : $country),
            'days' => $days,
            'q' => $q,
            'categoryId' => $categoryId,
            'country' => $country,
            'regionOptions' => $regionOptions,
            'pendingRu' => $translator->countPendingDays(),
            'defaultLanguage' => Language::admin(),
        ]);
    }

    /**
     * @return Collection<int, Category>
     */
    private function regionOptionsForCountry(?string $countryCode)
    {
        $names = [];
        $map = (array) config('travel_regions.regions', []);
        if ($countryCode) {
            $map = [$countryCode => $map[$countryCode] ?? []];
        }
        foreach ($map as $regions) {
            foreach ((array) $regions as $row) {
                foreach (['ru', 'he'] as $k) {
                    $n = trim((string) ($row[$k] ?? ''));
                    if ($n !== '') {
                        $names[] = $n;
                    }
                }
            }
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            return collect();
        }

        return Category::query()
            ->with('descriptions')
            ->whereHas('descriptions', fn ($w) => $w->whereIn('name', $names))
            ->orderBy('sort_order')
            ->get();
    }

    public function create(): View
    {
        return view('admin.golden_days.create', [
            'pageTitle' => 'Новый golden day',
            'regionOptions' => $this->regionOptionsForCountry('UA'),
            'defaultLanguage' => Language::admin(),
            'hubOptions' => $this->hubOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title_ru' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'hub_key' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'string', 'in:arrival,departure,stay,nature,family,transfer,kosher'],
            'load' => ['nullable', 'string', 'in:soft,medium,hard'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
        ]);

        $titleRu = trim($data['title_ru']);
        $title = trim((string) ($data['title'] ?? '')) ?: $titleRu;
        $hubKey = trim((string) ($data['hub_key'] ?? '')) ?: null;
        $signature = 'ua-gd-manual-'.bin2hex(random_bytes(8));

        $day = GoldenDay::query()->create([
            'title' => $title,
            'title_ru' => $titleRu,
            'category_id' => $data['category_id'] ?? null,
            'hub_key' => $hubKey,
            'signature' => $signature,
            'is_active' => true,
            'usage_count' => 0,
            'stops_count' => 0,
            'meta' => array_filter([
                'kind' => $data['kind'] ?? 'stay',
                'load' => $data['load'] ?? null,
                'hub' => $hubKey,
                'manual' => true,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        $locationIds = array_values(array_unique(array_map('intval', $data['location_ids'] ?? [])));
        foreach ($locationIds as $i => $locationId) {
            $this->attachLocationAsStop($day, $locationId, $i);
        }
        $this->refreshDayDerivedFields($day);

        return redirect()
            ->route('admin.golden-days.show', $day)
            ->with('success', 'Golden day создан');
    }

    public function show(GoldenDay $golden_day, GoldenDayRuTranslator $translator, GoldenDayLabelSync $labelSync): View
    {
        $labelSync->attachFromHub($golden_day);
        $golden_day->load([
            'stops.location.descriptions',
            'stops.location.attributeValues.attribute.descriptions',
            'area.descriptions',
            'category.descriptions',
            'sourceSkeleton',
            'labels.descriptions',
        ]);
        $units = $translator->units($golden_day);
        $done = is_array($golden_day->ru_units) ? $golden_day->ru_units : [];
        $unitsDone = count(array_intersect_key($done, $units));
        $defaultLanguage = Language::admin();
        $dayCountry = $golden_day->countryCode();
        $allLabels = GoldenLabel::query()
            ->with('descriptions')
            ->where('status', true)
            ->whereIn('country_code', [$dayCountry, 'BOTH'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.golden_days.show', [
            'pageTitle' => 'Golden day #'.$golden_day->id,
            'item' => $golden_day,
            'unitsTotal' => count($units),
            'unitsDone' => $unitsDone,
            'lang' => request()->input('lang', $golden_day->hasRussian() ? 'ru' : 'he'),
            'allLabels' => $allLabels,
            'defaultLanguage' => $defaultLanguage,
            'labelsCountry' => $dayCountry,
            'regionOptions' => $this->regionOptionsForCountry($dayCountry === 'UA' ? 'UA' : null),
            'hubOptions' => $this->hubOptions(),
        ]);
    }

    public function update(Request $request, GoldenDay $golden_day): RedirectResponse
    {
        $data = $request->validate([
            'title_ru' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'hub_key' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'string', 'in:arrival,departure,stay,nature,family,transfer,kosher'],
            'load' => ['nullable', 'string', 'in:soft,medium,hard'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $meta = is_array($golden_day->meta) ? $golden_day->meta : [];
        if (array_key_exists('kind', $data)) {
            $meta['kind'] = $data['kind'] ?: null;
        }
        if (array_key_exists('load', $data)) {
            $meta['load'] = $data['load'] ?: null;
        }
        $hubKey = array_key_exists('hub_key', $data) ? (trim((string) $data['hub_key']) ?: null) : $golden_day->hub_key;
        if ($hubKey) {
            $meta['hub'] = $hubKey;
        }

        $golden_day->forceFill([
            'title' => array_key_exists('title', $data) ? (trim((string) $data['title']) ?: $golden_day->title) : $golden_day->title,
            'title_ru' => array_key_exists('title_ru', $data) ? (trim((string) $data['title_ru']) ?: null) : $golden_day->title_ru,
            'category_id' => array_key_exists('category_id', $data)
                ? ($data['category_id'] ? (int) $data['category_id'] : null)
                : $golden_day->category_id,
            'hub_key' => $hubKey,
            'is_active' => $request->boolean('is_active'),
            'meta' => $meta,
        ])->save();

        $this->refreshDayDerivedFields($golden_day->fresh(['stops']));

        return redirect()
            ->route('admin.golden-days.show', $golden_day)
            ->with('success', 'День сохранён');
    }

    public function storeStop(Request $request, GoldenDay $golden_day): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $maxOrder = (int) $golden_day->stops()->max('sort_order');
        $stop = $this->attachLocationAsStop($golden_day, (int) $data['location_id'], $maxOrder + 1);
        $this->refreshDayDerivedFields($golden_day->fresh(['stops']));

        $lang = Language::admin();

        return response()->json([
            'ok' => true,
            'stop' => [
                'id' => $stop->id,
                'sort_order' => $stop->sort_order,
                'location_id' => $stop->location_id,
                'name' => $stop->name,
                'name_ru' => $stop->name_ru,
                'location_name' => $stop->location?->nameFor($lang),
                'edit_url' => route('admin.locations.edit', $stop->location_id),
            ],
        ]);
    }

    public function destroyStop(GoldenDay $golden_day, GoldenDayStop $stop): JsonResponse
    {
        if ((int) $stop->golden_day_id !== (int) $golden_day->id) {
            return response()->json(['ok' => false, 'message' => 'Stop does not belong to this day'], 404);
        }

        $stop->delete();
        $this->refreshDayDerivedFields($golden_day->fresh(['stops']));

        return response()->json(['ok' => true]);
    }

    public function reorderStops(Request $request, GoldenDay $golden_day): JsonResponse
    {
        $data = $request->validate([
            'stop_ids' => ['required', 'array', 'min:1'],
            'stop_ids.*' => ['integer'],
        ]);

        $ids = array_values(array_map('intval', $data['stop_ids']));
        $owned = $golden_day->stops()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($owned) !== count($ids)) {
            return response()->json(['ok' => false, 'message' => 'Некоторые stops не принадлежат дню'], 422);
        }

        foreach ($ids as $i => $stopId) {
            GoldenDayStop::query()->where('id', $stopId)->update(['sort_order' => $i]);
        }
        $this->refreshDayDerivedFields($golden_day->fresh(['stops']));

        return response()->json(['ok' => true]);
    }

    public function syncLabels(Request $request, GoldenDay $golden_day, GoldenDayLabelSync $labelSync): RedirectResponse
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('label_ids', []))));
        $dayCountry = $golden_day->countryCode();
        $valid = GoldenLabel::query()
            ->where('status', true)
            ->whereIn('country_code', [$dayCountry, 'BOTH'])
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
        $golden_day->labels()->sync($valid);
        // keep hub_* from hub_key even if unchecked by mistake
        $labelSync->attachFromHub($golden_day);

        return redirect()
            ->back()
            ->with('success', 'Метки сохранены ('.$dayCountry.')');
    }

    public function syncHubLabels(GoldenDayLabelSync $labelSync): RedirectResponse
    {
        @set_time_limit(600);
        $result = $labelSync->syncAllFromHubKeys();

        return redirect()
            ->route('admin.golden-days.index')
            ->with('success', "Метки хабов: дней {$result['days']}, привязок {$result['attached']}");
    }

    public function rebuild(Request $request, GoldenDayBuilder $builder): RedirectResponse
    {
        $fresh = $request->boolean('fresh', true);
        $result = $builder->buildFromSkeletons([
            'fresh' => $fresh,
            'min_stops' => 1,
        ]);

        return redirect()
            ->route('admin.golden-days.index')
            ->with('success', "Golden days: created {$result['created']}, reused {$result['reused']}, stops {$result['stops']} (linked {$result['linked']})");
    }

    public function toggle(GoldenDay $golden_day): RedirectResponse
    {
        $golden_day->is_active = ! $golden_day->is_active;
        $golden_day->save();

        return redirect()
            ->back()
            ->with('success', $golden_day->is_active ? 'Day activated' : 'Day deactivated');
    }

    public function linkStop(Request $request, GoldenDay $golden_day, GoldenDayStop $stop): JsonResponse
    {
        if ((int) $stop->golden_day_id !== (int) $golden_day->id) {
            return response()->json(['ok' => false, 'message' => 'Stop does not belong to this day'], 404);
        }

        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $location = Location::query()->with('descriptions')->findOrFail((int) $data['location_id']);
        $names = $this->locationNames($location);
        $stop->location_id = $location->id;
        $stop->source_model = $location->model ? (string) $location->model : $stop->source_model;
        $stop->name = $names['he'] ?: $stop->name;
        $stop->name_ru = $names['ru'] ?: $stop->name_ru;
        $stop->save();
        $this->refreshDayDerivedFields($golden_day->fresh(['stops']));

        $lang = Language::admin();

        return response()->json([
            'ok' => true,
            'stop_id' => $stop->id,
            'location_id' => $location->id,
            'location_name' => $location->nameFor($lang),
            'name' => $stop->name,
            'name_ru' => $stop->name_ru,
            'edit_url' => route('admin.locations.edit', $location->id),
        ]);
    }

    public function unlinkStop(GoldenDay $golden_day, GoldenDayStop $stop): JsonResponse
    {
        if ((int) $stop->golden_day_id !== (int) $golden_day->id) {
            return response()->json(['ok' => false, 'message' => 'Stop does not belong to this day'], 404);
        }

        $stop->location_id = null;
        $stop->save();

        return response()->json(['ok' => true, 'stop_id' => $stop->id]);
    }

    public function translateRu(Request $request, GoldenDay $golden_day, GoldenDayRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $limit = max(1, min(40, (int) $request->input('limit', 20)));

        try {
            $result = $translator->translateBatch($golden_day, $limit);
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

    public function translateRuBatch(Request $request, GoldenDayRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $daysLimit = max(1, min(3, (int) $request->input('days', 1)));

        try {
            $result = $translator->translateNextPendingDays($daysLimit);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = [
            'ok' => empty($result['warning']),
            'processed' => $result['processed'],
            'remaining' => $result['remaining_days'],
            'remaining_days' => $result['remaining_days'],
            'day_id' => $result['day_id'],
            'done' => $result['done'],
            'total' => $result['remaining_days'] + $result['processed'],
        ];
        if (! empty($result['warning'])) {
            $payload['message'] = $result['warning'];
        }

        return response()->json($payload, empty($result['warning']) ? 200 : 422);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function hubOptions(): array
    {
        return [
            ['key' => 'lviv', 'label' => 'Львов'],
            ['key' => 'carpathians', 'label' => 'Карпаты'],
            ['key' => 'uzhhorod', 'label' => 'Ужгород'],
            ['key' => 'kyiv', 'label' => 'Киев'],
            ['key' => 'uman', 'label' => 'Умань'],
            ['key' => 'odesa', 'label' => 'Одесса'],
            ['key' => 'mykolaiv', 'label' => 'Николаев'],
            ['key' => 'kharkiv', 'label' => 'Харьков'],
        ];
    }

    /**
     * @return array{he: string, ru: string}
     */
    private function locationNames(Location $location): array
    {
        $he = Language::query()->where('code', 'he')->first();
        $ru = Language::query()->where('code', 'ru')->first();
        $location->loadMissing('descriptions');

        return [
            'he' => trim((string) ($location->descriptions->firstWhere('language_id', $he?->id)?->name
                ?? $location->nameFor($he))),
            'ru' => trim((string) ($location->descriptions->firstWhere('language_id', $ru?->id)?->name
                ?? $location->nameFor($ru))),
        ];
    }

    private function attachLocationAsStop(GoldenDay $day, int $locationId, int $sortOrder): GoldenDayStop
    {
        $location = Location::query()->with('descriptions')->findOrFail($locationId);
        $names = $this->locationNames($location);

        return GoldenDayStop::query()->create([
            'golden_day_id' => $day->id,
            'sort_order' => $sortOrder,
            'location_id' => $location->id,
            'source_model' => $location->model ? (string) $location->model : null,
            'name' => $names['he'] !== '' ? $names['he'] : $names['ru'],
            'name_ru' => $names['ru'] !== '' ? $names['ru'] : $names['he'],
        ]);
    }

    private function refreshDayDerivedFields(GoldenDay $day): void
    {
        $day->loadMissing('stops');
        $stops = $day->stops->sortBy('sort_order')->values();
        $namesRu = $stops->map(fn (GoldenDayStop $s) => trim((string) ($s->name_ru ?: $s->name)))->filter()->values();
        $namesHe = $stops->map(fn (GoldenDayStop $s) => trim((string) ($s->name ?: $s->name_ru)))->filter()->values();

        $blob = trim(implode(' ', array_filter([
            $day->title,
            $day->title_ru,
            $namesRu->implode(' '),
            $namesHe->implode(' '),
        ])));

        $day->forceFill([
            'stops_count' => $stops->count(),
            'start_point' => $namesHe->first(),
            'start_point_ru' => $namesRu->first(),
            'end_point' => $namesHe->last(),
            'end_point_ru' => $namesRu->last(),
            'search_blob' => $blob !== '' ? $blob : null,
        ])->save();
    }
}
