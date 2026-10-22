<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeDescription;
use App\Models\AttributeGroup;
use App\Models\AttributeGroupDescription;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\ManufacturerDescription;

class LocationCatalogAssigner
{
    /**
     * @return array{assigned: int, countries: int, regions: int, unknown: list<string>}
     */
    public function assign(?Location $only = null): array
    {
        $he = Language::where('code', 'he')->firstOrFail();
        $ru = Language::where('code', 'ru')->first();
        $manufacturers = $this->ensureManufacturers($he, $ru);
        $regions = $this->ensureRegions($he, $ru);
        $this->ensureAttributes($he, $ru);

        $unknown = [];
        $assigned = 0;
        $query = Location::query()->orderBy('id');
        if ($only) {
            $query->where('id', $only->id);
        }

        foreach ($query->cursor() as $location) {
            $meta = $location->heMeta() ?? [];
            $countryKey = $this->matchCountry((string) ($meta['country'] ?? ''));
            $regionKey = $this->matchRegion($countryKey, (string) ($meta['region'] ?? ''));

            if ($countryKey === null && trim((string) ($meta['country'] ?? '')) !== '') {
                $unknown[] = 'country: '.(string) $meta['country'].' / '.(string) ($meta['region'] ?? '').' #'.$location->model;
            } elseif ($countryKey !== null && $regionKey === null && ! $this->isSkippedRegion((string) ($meta['region'] ?? ''))) {
                $unknown[] = 'region: '.(string) ($meta['country'] ?? '').' / '.(string) $meta['region'].' #'.$location->model;
            }

            $manufacturerId = $countryKey ? ($manufacturers[$countryKey] ?? null) : null;
            $location->forceFill(['manufacturer_id' => $manufacturerId])->save();

            $regionId = ($countryKey && $regionKey) ? ($regions[$countryKey][$regionKey] ?? null) : null;
            $location->categories()->sync($regionId ? [$regionId] : []);
            $assigned++;
        }

        return [
            'assigned' => $assigned,
            'countries' => count($manufacturers),
            'regions' => collect($regions)->map(fn ($r) => count($r))->sum(),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['׳', '’', '`', '´'], "'", $value);

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /**
     * @return array<string, int>
     */
    private function ensureManufacturers(Language $he, ?Language $ru): array
    {
        $out = [];
        $sort = 0;
        foreach ((array) config('travel_regions.countries', []) as $code => $row) {
            $manufacturer = Manufacturer::firstOrCreate(
                ['code' => $code],
                ['sort_order' => $sort++, 'status' => true]
            );
            ManufacturerDescription::updateOrCreate(
                ['manufacturer_id' => $manufacturer->id, 'language_id' => $he->id],
                ['name' => $row['he']]
            );
            if ($ru) {
                ManufacturerDescription::updateOrCreate(
                    ['manufacturer_id' => $manufacturer->id, 'language_id' => $ru->id],
                    ['name' => $row['ru']]
                );
            }
            $out[$code] = $manufacturer->id;
        }

        return $out;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function ensureRegions(Language $he, ?Language $ru): array
    {
        $parentNames = (array) config('travel_regions.region_parent', []);
        $parent = Category::query()
            ->whereHas('descriptions', fn ($q) => $q->where('name', $parentNames['ru'] ?? 'Области'))
            ->first();
        if (! $parent) {
            $parent = Category::create(['parent_id' => null, 'sort_order' => 50, 'status' => true]);
            CategoryDescription::create(['category_id' => $parent->id, 'language_id' => $he->id, 'name' => $parentNames['he'] ?? 'אזורים']);
            if ($ru) {
                CategoryDescription::create(['category_id' => $parent->id, 'language_id' => $ru->id, 'name' => $parentNames['ru'] ?? 'Области']);
            }
        }

        $out = [];
        $sort = 0;
        foreach ((array) config('travel_regions.regions', []) as $country => $regions) {
            foreach ($regions as $key => $row) {
                $existing = Category::query()
                    ->where('parent_id', $parent->id)
                    ->whereHas('descriptions', fn ($q) => $q->where('name', $row['ru']))
                    ->first();
                if (! $existing) {
                    $existing = Category::create(['parent_id' => $parent->id, 'sort_order' => $sort++, 'status' => true]);
                    CategoryDescription::create(['category_id' => $existing->id, 'language_id' => $he->id, 'name' => $row['he']]);
                    if ($ru) {
                        CategoryDescription::create(['category_id' => $existing->id, 'language_id' => $ru->id, 'name' => $row['ru']]);
                    }
                }
                $out[$country][$key] = $existing->id;
            }
        }
        Category::rebuildPaths();

        return $out;
    }

    private function ensureAttributes(Language $he, ?Language $ru): void
    {
        if (AttributeGroup::query()->exists()) {
            return;
        }
        $gSort = 0;
        foreach ((array) config('travel_regions.attributes', []) as $pack) {
            $group = AttributeGroup::create(['sort_order' => $gSort++]);
            AttributeGroupDescription::create(['attribute_group_id' => $group->id, 'language_id' => $he->id, 'name' => $pack['group']['he']]);
            if ($ru) {
                AttributeGroupDescription::create(['attribute_group_id' => $group->id, 'language_id' => $ru->id, 'name' => $pack['group']['ru']]);
            }
            $aSort = 0;
            foreach ((array) ($pack['items'] ?? []) as $item) {
                $attr = Attribute::create(['attribute_group_id' => $group->id, 'sort_order' => $aSort++]);
                AttributeDescription::create(['attribute_id' => $attr->id, 'language_id' => $he->id, 'name' => $item['he']]);
                if ($ru) {
                    AttributeDescription::create(['attribute_id' => $attr->id, 'language_id' => $ru->id, 'name' => $item['ru']]);
                }
            }
        }
    }

    private function matchCountry(string $raw): ?string
    {
        $norm = self::normalize($raw);
        if ($norm === '') {
            return null;
        }
        foreach ((array) config('travel_regions.countries', []) as $code => $row) {
            $aliases = array_map([self::class, 'normalize'], array_merge([$row['he'], $row['ru'], $code], $row['aliases'] ?? []));
            if (in_array($norm, $aliases, true)) {
                return $code;
            }
        }

        return null;
    }

    private function matchRegion(?string $country, string $raw): ?string
    {
        if ($country === null || $this->isSkippedRegion($raw)) {
            return null;
        }
        $norm = self::normalize($raw);
        if ($norm === '') {
            return null;
        }
        foreach ((array) data_get(config('travel_regions.regions'), $country, []) as $key => $row) {
            $aliases = array_map([self::class, 'normalize'], array_merge([$row['he'], $row['ru'], $key], $row['aliases'] ?? []));
            if (in_array($norm, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    private function isSkippedRegion(string $raw): bool
    {
        $norm = self::normalize($raw);

        return $norm === '' || in_array($norm, array_map([self::class, 'normalize'], (array) config('travel_regions.skip_regions', [])), true);
    }
}
