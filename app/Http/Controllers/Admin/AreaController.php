<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AreaDescription;
use App\Models\Language;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AreaController extends Controller
{
    public function index()
    {
        $pageTitle = 'Areas';
        $defaultLanguage = Language::admin();
        $areas = Area::with('descriptions')
            ->withCount('locations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.areas.index', compact('pageTitle', 'areas', 'defaultLanguage'));
    }

    public function create()
    {
        return view('admin.areas.create', $this->formData());
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));

        DB::transaction(function () use ($request, $languages) {
            $item = Area::create($this->baseAttributes($request));
            $this->syncNames($item, $request, $languages);
        });

        return redirect()->route('admin.areas.index')->with('success', 'Район создан');
    }

    public function edit(Area $area)
    {
        $area->load(['descriptions', 'defaultBase.descriptions']);

        return view('admin.areas.edit', $this->formData() + ['area' => $area]);
    }

    public function update(Request $request, Area $area)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages, $area->id));

        DB::transaction(function () use ($request, $languages, $area) {
            $area->update($this->baseAttributes($request));
            $this->syncNames($area, $request, $languages);
        });

        return redirect()->route('admin.areas.index')->with('success', 'Сохранено');
    }

    public function destroy(Area $area)
    {
        $area->delete();

        return redirect()->route('admin.areas.index')->with('success', 'Удалено');
    }

    /**
     * Проставить area_id локациям в радиусе центра района (без координат — пропускаем).
     * Уже привязанные к другому району не трогаем, если не force.
     */
    public function assignLocations(Request $request, Area $area)
    {
        if ($area->lat === null || $area->lng === null || (float) $area->radius_km <= 0) {
            return back()->with('info', 'Укажите центр (lat/lng) и радиус района');
        }

        $force = $request->boolean('force');
        $radius = (float) $area->radius_km;
        $clat = (float) $area->lat;
        $clng = (float) $area->lng;
        $updated = 0;
        $skipped = 0;

        Location::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($area, $force, $radius, $clat, $clng, &$updated, &$skipped) {
                foreach ($chunk as $loc) {
                    $km = $this->distanceKm($clat, $clng, (float) $loc->lat, (float) $loc->lng);
                    if ($km > $radius) {
                        continue;
                    }
                    if ($loc->area_id && (int) $loc->area_id !== (int) $area->id && ! $force) {
                        $skipped++;
                        continue;
                    }
                    if ((int) $loc->area_id === (int) $area->id) {
                        continue;
                    }
                    $loc->area_id = $area->id;
                    $loc->save();
                    $updated++;
                }
            });

        $msg = "Привязано локаций: {$updated}";
        if ($skipped > 0) {
            $msg .= " (пропущено уже в другом районе: {$skipped}; включите «перезаписать» чтобы заменить)";
        }

        return back()->with('success', $msg);
    }

    private function formData(): array
    {
        $defaultLanguage = Language::admin();
        $baseOptions = Location::query()
            ->with('descriptions')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Location $l) => [
                'id' => $l->id,
                'label' => '#'.$l->id.' '.$l->nameFor($defaultLanguage),
            ]);

        return [
            'pageTitle' => 'Areas',
            'languages' => Language::forAdminForms(),
            'defaultLanguage' => $defaultLanguage,
            'baseOptions' => $baseOptions,
        ];
    }

    private function rules($languages, ?int $ignore = null): array
    {
        $rules = [
            'slug' => [
                'nullable',
                'string',
                'max:64',
                $ignore ? 'unique:areas,slug,'.$ignore : 'unique:areas,slug',
            ],
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:0.5|max:500',
            'default_base_location_id' => 'nullable|exists:locations,id',
            'min_days' => 'nullable|integer|min:1|max:14',
            'max_days' => 'nullable|integer|min:1|max:14',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];
        foreach ($languages as $language) {
            $rules['name_'.$language->code] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
        }

        return $rules;
    }

    private function baseAttributes(Request $request): array
    {
        $slug = trim((string) $request->input('slug', ''));
        if ($slug === '') {
            $default = Language::admin();
            $name = trim((string) $request->input('name_'.($default?->code ?? 'he'), ''));
            $slug = Str::slug($name !== '' ? $name : 'area-'.time(), '-');
            if ($slug === '') {
                $slug = 'area-'.time();
            }
        }

        $baseId = $request->filled('default_base_location_id')
            ? (int) $request->input('default_base_location_id')
            : null;

        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->input('lng') : null;

        // Если центр пуст — взять координаты базы ночёвки
        if (($lat === null || $lng === null) && $baseId) {
            $base = Location::query()->find($baseId);
            if ($base && $base->lat !== null && $base->lng !== null) {
                $lat = (float) $base->lat;
                $lng = (float) $base->lng;
            }
        }

        $min = max(1, (int) $request->input('min_days', 1));
        $max = max($min, (int) $request->input('max_days', 4));

        return [
            'slug' => $slug,
            'lat' => $lat,
            'lng' => $lng,
            'radius_km' => (float) ($request->input('radius_km') ?: 25),
            'default_base_location_id' => $baseId,
            'min_days' => $min,
            'max_days' => $max,
            'sort_order' => (int) $request->input('sort_order', 0),
            'status' => $request->boolean('status'),
        ];
    }

    private function syncNames(Area $item, Request $request, $languages): void
    {
        foreach ($languages as $language) {
            $name = trim((string) $request->input('name_'.$language->code, ''));
            if ($name === '') {
                $item->descriptions()->where('language_id', $language->id)->delete();

                continue;
            }
            AreaDescription::updateOrCreate(
                ['area_id' => $item->id, 'language_id' => $language->id],
                ['name' => $name]
            );
        }
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
