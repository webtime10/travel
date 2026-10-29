<?php

namespace App\Console\Commands;

use App\Models\GoldenDay;
use App\Models\GoldenDayStop;
use App\Models\GoldenLabel;
use App\Models\GoldenLabelDescription;
use App\Models\Language;
use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ensure golden labels for PHP slot assembly (no Gemini), retag UA seeded days.
 */
class EnsureGoldenLabelsCommand extends Command
{
    protected $signature = 'golden-labels:ensure-php
        {--retag-ua : Re-attach labels on ua-gd-* golden days}
        {--ensure-gates : Mark first/last day per UA region as arrival/departure}';

    protected $description = 'Create/update golden labels needed for PHP route slots; optional UA retag';

    public function handle(): int
    {
        $he = Language::where('code', 'he')->firstOrFail();
        $ru = Language::where('code', 'ru')->firstOrFail();

        $defs = $this->definitions();
        $sort = 10;
        foreach ($defs as $slug => [$nameRu, $nameHe]) {
            $country = str_starts_with($slug, 'hub-')
                ? (str_contains($slug, 'lviv') || str_contains($slug, 'carpath') || str_contains($slug, 'uzhhorod')
                    || str_contains($slug, 'kyiv') || str_contains($slug, 'uman') || str_contains($slug, 'odesa')
                    || str_contains($slug, 'mykolaiv') || str_contains($slug, 'kharkiv') ? 'UA' : 'IT')
                : 'BOTH';
            $label = GoldenLabel::query()->firstOrCreate(
                ['slug' => $slug],
                ['sort_order' => $sort, 'status' => true, 'country_code' => $country]
            );
            $label->forceFill([
                'status' => true,
                'sort_order' => $sort,
                'country_code' => $label->country_code ?: $country,
            ])->save();
            GoldenLabelDescription::updateOrCreate(
                ['golden_label_id' => $label->id, 'language_id' => $ru->id],
                ['name' => $nameRu]
            );
            GoldenLabelDescription::updateOrCreate(
                ['golden_label_id' => $label->id, 'language_id' => $he->id],
                ['name' => $nameHe]
            );
            $sort += 10;
        }

        $this->info('Labels ready: '.count($defs));

        if ($this->option('ensure-gates') || $this->option('retag-ua')) {
            $g = $this->ensureArrivalDepartureGates();
            $this->info("UA gates (arrival/departure): {$g}");
        }

        if ($this->option('retag-ua')) {
            $trek = $this->ensureCarpathianTrekDays();
            $this->info("Carpathian trek days seeded: {$trek}");
            $n = $this->retagUa();
            $this->info("Retagged UA golden days: {$n}");
        }

        return self::SUCCESS;
    }

    /**
     * Ensure ≥1 Карпаты day includes Говерла → метка «Активный трек».
     */
    private function ensureCarpathianTrekDays(): int
    {
        $goverla = Location::query()
            ->where('meta->title', 'like', '%оверл%')
            ->orWhere('meta', 'like', '%Говерл%')
            ->first();
        if (! $goverla) {
            return 0;
        }

        $already = GoldenDay::query()
            ->where('hub_key', 'carpathians')
            ->whereHas('stops', fn ($q) => $q->where('location_id', $goverla->id))
            ->count();
        if ($already > 0) {
            return 0;
        }

        $target = GoldenDay::query()
            ->where('hub_key', 'carpathians')
            ->where('meta->kind', 'nature')
            ->with('stops')
            ->orderBy('id')
            ->first()
            ?? GoldenDay::query()
                ->where('hub_key', 'carpathians')
                ->whereNotIn('meta->kind', ['arrival', 'departure'])
                ->with('stops')
                ->orderBy('id')
                ->first();

        if (! $target || $target->stops->isEmpty()) {
            return 0;
        }

        /** @var GoldenDayStop $last */
        $last = $target->stops->sortByDesc('sort_order')->first()
            ?? $target->stops->last();
        $title = (string) data_get($goverla->meta, 'title', 'Говерла');
        $last->forceFill([
            'location_id' => $goverla->id,
            'name_ru' => $title,
            'name' => $title,
        ])->save();

        $titleRu = (string) $target->title_ru;
        if (! str_contains(mb_strtolower($titleRu), 'говерл')) {
            $titleRu = preg_replace('/\s*\+\s*[^+]+$/u', '', $titleRu) ?? $titleRu;
            $titleRu = rtrim($titleRu).' + Говерла (трек)';
            $target->forceFill([
                'title_ru' => $titleRu,
                'title' => $titleRu,
                'end_point' => $title,
                'end_point_ru' => $title,
                'search_blob' => mb_strtolower($titleRu.' carpathians '.$title),
            ])->save();
        }

        return 1;
    }

    /**
     * In each UA region (signature ua-gd-{region}-{idx}-…): idx 0 = arrival, max idx = departure.
     * Stops rebuilt as a light day: café / short walk (~2h), not heavy hubs.
     */
    private function ensureArrivalDepartureGates(): int
    {
        $ru = Language::where('code', 'ru')->first();
        $he = Language::where('code', 'he')->first();
        $byRegion = [];
        GoldenDay::query()
            ->where('signature', 'like', 'ua-gd-%')
            ->orderBy('id')
            ->get()
            ->each(function (GoldenDay $day) use (&$byRegion) {
                if (! preg_match('/^ua-gd-([a-z0-9_]+)-(\d+)-/', (string) $day->signature, $m)) {
                    return;
                }
                $byRegion[$m[1]][(int) $m[2]] = $day;
            });

        $n = 0;
        foreach ($byRegion as $region => $daysByIdx) {
            ksort($daysByIdx, SORT_NUMERIC);
            $indices = array_keys($daysByIdx);
            if ($indices === []) {
                continue;
            }
            $firstIdx = $indices[0];
            $lastIdx = $indices[count($indices) - 1];
            $gates = [$firstIdx => 'arrival'];
            if ($lastIdx !== $firstIdx) {
                $gates[$lastIdx] = 'departure';
            }

            foreach ($gates as $idx => $gate) {
                /** @var GoldenDay $day */
                $day = $daysByIdx[$idx];
                $meta = is_array($day->meta) ? $day->meta : [];
                $meta['kind'] = $gate;
                // Не затирать средний въезд («лёгкий+») лёгким кафе+прогулка.
                $existingLoad = (string) ($meta['load'] ?? '');
                if ($existingLoad === 'medium' || $existingLoad === 'hard') {
                    $day->forceFill(['meta' => $meta])->save();
                    $n++;

                    continue;
                }
                $meta['load'] = 'soft';
                $picks = $this->lightStopsForDay($day, $gate);
                $regionRu = (string) ($meta['region'] ?? $region);
                $hubTitle = $gate === 'arrival' ? 'прилёт / въезд (лёгкий)' : 'выезд';
                $names = $picks->map(function (Location $loc) use ($ru) {
                    return $loc->descriptions->firstWhere('language_id', $ru?->id)?->name
                        ?? $loc->nameFor($ru);
                })->values();
                $titleRu = $regionRu.' · '.$hubTitle.': '.($names[0] ?? 'лёгкий день');
                if ($names->count() > 1) {
                    $titleRu .= ' → '.$names[1];
                }

                $day->forceFill([
                    'meta' => $meta,
                    'title_ru' => $titleRu,
                    'title' => $titleRu,
                    'start_point' => $names[0] ?? null,
                    'start_point_ru' => $names[0] ?? null,
                    'end_point' => $names[$names->count() - 1] ?? null,
                    'end_point_ru' => $names[$names->count() - 1] ?? null,
                    'stops_count' => $picks->count(),
                ])->save();

                $day->stops()->delete();
                foreach ($picks->values() as $i => $loc) {
                    $nameRu = $names[$i] ?? $loc->nameFor($ru);
                    $nameHe = $loc->descriptions->firstWhere('language_id', $he?->id)?->name ?? $nameRu;
                    GoldenDayStop::create([
                        'golden_day_id' => $day->id,
                        'sort_order' => $i,
                        'location_id' => $loc->id,
                        'source_model' => $loc->model,
                        'name' => $nameHe,
                        'name_ru' => $nameRu,
                        'lat' => $loc->lat,
                        'lng' => $loc->lng,
                    ]);
                }
                $n++;
            }
        }

        return $n;
    }

    /**
     * Soft arrival/departure: café + short stroll (prefer weight≤4, ~1–2h each).
     *
     * @return Collection<int, Location>
     */
    private function lightStopsForDay(GoldenDay $day, string $gate): Collection
    {
        $categoryId = (int) ($day->category_id ?? 0);
        $q = Location::query()->with('descriptions');
        if ($categoryId > 0) {
            $q->whereHas('categories', fn ($w) => $w->where('categories.id', $categoryId));
        }

        $pool = $q->orderBy('weight')->orderBy('id')->get();
        if ($pool->isEmpty()) {
            return collect();
        }

        $hubLat = null;
        $hubLng = null;
        foreach ($pool as $cand) {
            $ptype = mb_strtolower((string) data_get($cand->meta, 'place_type', ''));
            if (str_contains($ptype, 'хаб') && $cand->lat !== null && $cand->lng !== null) {
                $hubLat = (float) $cand->lat;
                $hubLng = (float) $cand->lng;
                break;
            }
        }

        $score = function (Location $loc) use ($gate, $hubLat, $hubLng): int {
            $type = mb_strtolower((string) data_get($loc->meta, 'place_type', ''));
            $act = mb_strtolower((string) data_get($loc->meta, 'activity', ''));
            $name = mb_strtolower($loc->nameFor());
            $w = (int) ($loc->weight ?? 5);
            $h = (float) ($loc->visit_hours ?? 2);
            $s = 0;
            if (str_contains($type, 'еда') || str_contains($name, 'кафе') || str_contains($name, 'кофе')
                || str_contains($name, 'пекар') || str_contains($name, 'пицц') || str_contains($name, 'ресторан')
                || str_contains($name, 'babel') || str_contains($name, 'бабель')) {
                $s += 40;
            }
            if (str_contains($type, 'по дороге') || str_contains($name, 'сквер') || str_contains($name, 'алле')
                || str_contains($name, 'фотостоп') || str_contains($name, 'площад')
                || str_contains($name, 'маразлиев') || str_contains($name, 'шевченк') || str_contains($name, 'прогул')) {
                $s += 25;
            }
            if (str_contains($name, 'синагог')) {
                $s += 20;
            }
            if ($act === 'спокойно') {
                $s += 10;
            }
            if ($w <= 3) {
                $s += 15;
            } elseif ($w <= 4) {
                $s += 8;
            }
            if ($h > 0 && $h <= 2) {
                $s += 10;
            }
            if (str_contains($type, 'актив') || str_contains($act, 'актив') || $w >= 8
                || str_contains($type, 'хаб') || str_contains($name, 'аквапарк') || str_contains($name, 'сплав')) {
                $s -= 50;
            }
            // Дальние «выездные» / тест (устрицы) — не для въезда в город
            if (str_contains($type, 'выездн') || str_contains($name, 'устрич') || str_contains($name, 'тест')
                || str_contains($name, 'вилков') || str_contains($name, 'заток') || str_contains($name, 'шабо')) {
                $s -= 80;
            }
            if ($hubLat !== null && $hubLng !== null && $loc->lat !== null && $loc->lng !== null) {
                $km = $this->haversineKm($hubLat, $hubLng, (float) $loc->lat, (float) $loc->lng);
                if ($km <= 3) {
                    $s += 35;
                } elseif ($km <= 8) {
                    $s += 15;
                } elseif ($km > 20) {
                    $s -= 60;
                }
            }
            if ($gate === 'departure' && (str_contains($type, 'еда') || str_contains($name, 'кофе'))) {
                $s += 5;
            }

            return $s;
        };

        $ranked = $pool->sortByDesc($score)->values();
        $food = $ranked->first(function (Location $loc) {
            $type = mb_strtolower((string) data_get($loc->meta, 'place_type', ''));
            $name = mb_strtolower($loc->nameFor());
            if (str_contains($type, 'выездн') || str_contains($name, 'устрич') || str_contains($name, 'тест')) {
                return false;
            }

            return str_contains($type, 'еда') || str_contains($name, 'кафе') || str_contains($name, 'кофе')
                || str_contains($name, 'пекар') || str_contains($name, 'пицц') || str_contains($name, 'ресторан')
                || str_contains($name, 'babel') || str_contains($name, 'бабель');
        });
        $walk = $ranked->first(function (Location $loc) use ($food) {
            if ($food && $loc->id === $food->id) {
                return false;
            }
            $type = mb_strtolower((string) data_get($loc->meta, 'place_type', ''));
            $name = mb_strtolower($loc->nameFor());
            $w = (int) ($loc->weight ?? 5);
            if (str_contains($type, 'еда') || str_contains($type, 'актив') || str_contains($type, 'хаб')
                || str_contains($type, 'выездн') || str_contains($name, 'устрич')) {
                return false;
            }

            return $w <= 5 || str_contains($name, 'сквер') || str_contains($name, 'алле')
                || str_contains($name, 'фотостоп') || str_contains($type, 'по дороге')
                || str_contains($name, 'маразлиев') || str_contains($name, 'шевченк') || str_contains($name, 'синагог')
                || str_contains($type, 'достопримечательность');
        });

        $picks = collect();
        if ($food) {
            $picks->push($food);
        }
        if ($walk) {
            $picks->push($walk);
        }
        if ($picks->count() < 2) {
            foreach ($ranked as $loc) {
                if ($picks->contains(fn (Location $x) => $x->id === $loc->id)) {
                    continue;
                }
                $picks->push($loc);
                if ($picks->count() >= 2) {
                    break;
                }
            }
        }

        return $picks->take(2)->values();
    }

    /**
     * Core set for PHP: role + hub + theme + audience + load.
     *
     * @return array<string, array{0:string,1:string}>
     */
    private function definitions(): array
    {
        return [
            // Trip role (slot form) — перелёт ≠ выезд; въезд ≠ экскурсия
            'arrival' => ['Въезд', 'הגעה'],
            'departure' => ['Выезд', 'עזיבה'],
            'flight' => ['Перелёт', 'טיסה'],
            'stay' => ['День у базы', 'יום ליד הבסיס'],
            'transfer' => ['Переезд', 'מעבר'],

            // Способ выезда / перелёта (участвует в поиске)
            'plane' => ['Самолёт', 'מטוס'],
            'bus' => ['Автобус', 'אוטובוס'],
            'car' => ['Автомобиль', 'רכב'],

            // Load 1–3 (mutually exclusive: soft | medium | hard)
            'soft' => ['1 · Лёгкий', '1 · קל'],
            'medium' => ['2 · Средний', '2 · בינוני'],
            'hard' => ['3 · Насыщенный', '3 · עמוס'],
            'long-day' => ['Длинный день', 'יום ארוך'],
            'short-day' => ['Короткий / полдня', 'חצי יום'],
            'quality-high' => ['Сильный день', 'יום חזק'],

            // Theme
            'nature' => ['Природа', 'טבע'],
            'mountains' => ['Горы', 'הרים'],
            'sea' => ['Море / пляж', 'ים / חוף'],
            'city' => ['Город / культура', 'עיר / תרבות'],
            'panorama' => ['Панорама', 'תצפית'],
            'active' => ['Активный отдых', 'פעילות קלה'],
            'active-trek' => ['Активный трек', 'טרק פעיל'],
            'food' => ['Еда / гастро', 'אוכל'],
            'pilgrimage' => ['Паломничество', 'עלייה לרגל'],

            // Audience: большинство дней ОК с детьми; no-kids = явно не для детей
            'family' => ['С детьми', 'עם ילדים'],
            'no-kids' => ['Без детей', 'ללא ילדים'],
            'kosher-friendly' => ['Кошер-friendly', 'ידידותי לכשר'],

            // UA hubs
            'hub-lviv' => ['Львов (база)', 'לבוב (בסיס)'],
            'hub-carpathians' => ['Карпаты (база)', 'קרפטים (בסיס)'],
            'hub-uzhhorod' => ['Ужгород (база)', 'אוז׳הורוד (בסיס)'],
            'hub-kyiv' => ['Киев (база)', 'קייב (בסיס)'],
            'hub-uman' => ['Умань (база)', 'אומן (בסיס)'],
            'hub-odesa' => ['Одесса (база)', 'אודסה (בסיס)'],
            'hub-mykolaiv' => ['Николаев (база)', 'מיקולאייב (בסיס)'],
            'hub-kharkiv' => ['Харьков (база)', 'חרקוב (בסיס)'],
        ];
    }

    private function retagUa(): int
    {
        $slugToId = GoldenLabel::query()->where('status', true)->pluck('id', 'slug');
        $n = 0;

        GoldenDay::query()
            ->where('signature', 'like', 'ua-gd-%')
            ->with('stops.location')
            ->orderBy('id')
            ->chunkById(50, function ($days) use ($slugToId, &$n) {
                foreach ($days as $day) {
                    $slugs = $this->slugsForDay($day);
                    $ids = [];
                    foreach ($slugs as $slug) {
                        if (isset($slugToId[$slug])) {
                            $ids[] = (int) $slugToId[$slug];
                        }
                    }
                    $day->labels()->sync(array_values(array_unique($ids)));
                    $n++;
                }
            });

        return $n;
    }

    /**
     * @return list<string>
     */
    private function slugsForDay(GoldenDay $day): array
    {
        $hub = strtolower(trim((string) ($day->hub_key ?? '')));
        $kind = (string) data_get($day->meta, 'kind', 'stay');
        $title = mb_strtolower((string) ($day->title_ru ?? $day->title ?? ''));
        $slugs = [];

        if ($hub !== '') {
            $slugs[] = 'hub-'.$hub;
        }

        $slugs[] = match ($kind) {
            'transfer' => 'transfer',
            'nature' => 'nature',
            'family' => 'family',
            'kosher' => 'kosher-friendly',
            'arrival' => 'arrival',
            'departure' => 'departure',
            default => 'stay',
        };

        // Theme by hub / title
        if (in_array($hub, ['carpathians'], true) || str_contains($title, 'карпат') || str_contains($title, 'говерл')) {
            $slugs[] = 'mountains';
            $slugs[] = 'nature';
        }
        if (in_array($hub, ['odesa', 'mykolaiv'], true) || str_contains($title, 'море') || str_contains($title, 'пляж') || str_contains($title, 'вилков')) {
            $slugs[] = 'sea';
        }
        if (in_array($hub, ['lviv', 'kyiv', 'kharkiv', 'uzhhorod'], true) || str_contains($title, 'город') || str_contains($title, 'центр')) {
            $slugs[] = 'city';
        }
        if (in_array($hub, ['uman'], true) || str_contains($title, 'бердичев') || str_contains($title, 'палом') || str_contains($title, 'хасид')) {
            $slugs[] = 'pilgrimage';
        }
        if ($kind === 'nature') {
            $slugs[] = 'nature';
            $slugs[] = 'panorama';
        }
        if ($kind === 'family') {
            $slugs[] = 'family';
        }

        // Load from stop visit_hours / weight — pick exactly one of soft|medium|hard
        $hours = 0.0;
        $maxW = 0;
        $hasActive = false;
        $hasHardTrek = false;
        $hasKosher = false;
        $hasFamilyStop = false;
        $stopCount = 0;
        foreach ($day->stops as $stop) {
            $loc = $stop->location;
            if (! $loc) {
                continue;
            }
            $stopCount++;
            $hours += (float) ($loc->visit_hours ?? 2);
            $maxW = max($maxW, (int) ($loc->weight ?? 5));
            $type = (string) data_get($loc->meta, 'place_type', '');
            $act = (string) data_get($loc->meta, 'activity', '');
            $stopName = mb_strtolower(trim(implode(' ', array_filter([
                (string) ($stop->name_ru ?? ''),
                (string) ($stop->name ?? ''),
                (string) data_get($loc->meta, 'title', ''),
            ]))));
            if (str_contains($type, 'актив') || str_contains($act, 'актив')) {
                $hasActive = true;
            }
            if (str_contains($stopName, 'говерл')
                || str_contains($stopName, 'петрос')
                || preg_match('/восхожд|перевал|хайк|трек/u', $stopName)
                || str_contains($act, 'слож')
                || str_contains($act, 'тяжёл')
                || str_contains($act, 'тяжел')) {
                $hasHardTrek = true;
            }
            if (str_contains($stopName, 'буковель')
                || str_contains($stopName, 'тростян')
                || str_contains($stopName, 'подъёмник')
                || str_contains($stopName, 'подъемник')) {
                $hasActive = true;
            }
            if ((bool) data_get($loc->meta, 'family')) {
                $hasFamilyStop = true;
            }
            if (($loc->kosher_food ?? '') === 'yes') {
                $hasKosher = true;
            }
            if (str_contains($type, 'еда')) {
                $slugs[] = 'food';
            }
        }

        $isGate = $kind === 'arrival' || $kind === 'departure';

        if (! $isGate) {
            if ($hours >= 7 || $kind === 'transfer') {
                $slugs[] = 'long-day';
            } elseif ($hours > 0 && $hours <= 3) {
                $slugs[] = 'short-day';
            }
            $isHardTrek = $hasHardTrek
                || str_contains($title, 'говерл')
                || str_contains($title, 'трек')
                || preg_match('/восхожд|хайк|перевал/u', $title);
            $isSoftActive = str_contains($title, 'подъёмник')
                || str_contains($title, 'подъемник')
                || str_contains($title, 'буковель')
                || str_contains($title, 'тростян')
                || str_contains($title, 'лыж')
                || str_contains($title, 'сноуборд')
                || str_contains($title, 'каяк')
                || str_contains($title, 'сплав')
                || str_contains($title, 'верёвоч')
                || $hasActive;
            // Карпаты: nature без курортного «активного отдыха» → тяжёлый трек-кандид
            if (! $isHardTrek && ! $isSoftActive && $hub === 'carpathians' && $kind === 'nature') {
                $isHardTrek = true;
            }
            if ($isHardTrek) {
                $slugs[] = 'active-trek';
                $slugs[] = 'no-kids';
            } elseif ($isSoftActive) {
                $slugs[] = 'active';
            }
            if ($hasFamilyStop || $kind === 'family') {
                $slugs[] = 'family';
            }
            // Активный насыщенный день без family-стопов → не для детей
            if (! in_array('family', $slugs, true)
                && (in_array('active-trek', $slugs, true)
                    || ($isSoftActive && ($hours >= 6 || str_contains($title, 'говерл'))))) {
                $slugs[] = 'no-kids';
            }
        }

        // Arrival/departure: soft by default; medium allowed («лёгкий+» / средний по приезду).
        if ($isGate) {
            $gateLoad = (string) data_get($day->meta, 'load', '');
            if (! in_array($gateLoad, ['soft', 'medium', 'hard'], true)) {
                $gateLoad = str_contains(mb_strtolower((string) ($day->title_ru ?? '')), 'средн') ? 'medium' : 'soft';
            }
            $slugs[] = $gateLoad === 'hard' ? 'medium' : $gateLoad; // въезд не делаем «насыщенным»
            if ($gateLoad === 'soft') {
                $slugs[] = 'short-day';
                $slugs[] = 'food';
            }
            if (in_array($hub, ['lviv', 'kyiv', 'kharkiv', 'uzhhorod'], true)) {
                $slugs[] = 'city';
            }
        } else {
            $loadScore = 0;
            if ($kind === 'nature' || $kind === 'transfer') {
                $loadScore += 1;
            }
            if ($hasActive || str_contains($title, 'говерл')) {
                $loadScore += 2;
            }
            if ($hours >= 7) {
                $loadScore += 2;
            } elseif ($hours >= 4.5) {
                $loadScore += 1;
            }
            if ($stopCount >= 4) {
                $loadScore += 1;
            }
            if ($kind === 'family' && $hasActive) {
                $loadScore = max(1, min(2, $loadScore));
            }

            $slugs[] = match (true) {
                $loadScore >= 3 => 'hard',
                $loadScore >= 1 => 'medium',
                default => 'soft',
            };
            if ($maxW >= 9) {
                $slugs[] = 'quality-high';
            }
        }

        if ($hasKosher || $kind === 'kosher') {
            $slugs[] = 'kosher-friendly';
        }

        // First/last day heuristics by title
        if (str_contains($title, 'прилёт') || str_contains($title, 'въезд') || str_contains($title, 'arrival')) {
            $slugs[] = 'arrival';
        }
        if (str_contains($title, 'выезд') || str_contains($title, 'departure')) {
            $slugs[] = 'departure';
        }
        // Перелёт ≠ выезд (наземный)
        if (str_contains($title, 'перелёт') || str_contains($title, 'перелет') || str_contains($title, 'flight')) {
            $slugs[] = 'flight';
            $slugs[] = 'plane';
        }
        if (str_contains($title, 'самолёт') || str_contains($title, 'самолет') || str_contains($title, 'аэропорт') || str_contains($title, 'аеропорт')) {
            $slugs[] = 'plane';
            if ($isGate || str_contains($title, 'перелёт') || str_contains($title, 'перелет')) {
                $slugs[] = 'flight';
            }
        }
        if (str_contains($title, 'автобус') || str_contains($title, 'автовокзал')) {
            $slugs[] = 'bus';
        }
        if (str_contains($title, 'автомобил') || str_contains($title, 'на машине') || str_contains($title, 'на авто')) {
            $slugs[] = 'car';
        }

        return array_values(array_unique($slugs));
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
