<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\GoldenDay;
use App\Models\GoldenDayStop;
use App\Models\GoldenLabel;
use App\Models\GoldenLabelDescription;
use App\Models\Language;
use App\Models\Location;
use App\Models\Manufacturer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeedUkraineGoldenDaysCommand extends Command
{
    protected $signature = 'golden-days:seed-ukraine
        {--per-region=20 : Max golden days per UA tourist region}
        {--fresh : Delete existing UA-seeded golden days first}';

    protected $description = 'Seed Ukraine golden days by region (with labels) for PHP slot training';

    /** @var array<string, string> region config key → hub_key */
    private array $hubByRegion = [
        'lviv_castles' => 'lviv',
        'carpathians_galicia' => 'carpathians',
        'zakarpattia' => 'uzhhorod',
        'kyiv_ring' => 'kyiv',
        'uman_berdychiv' => 'uman',
        'odesa_danube' => 'odesa',
        'mykolaiv_bug' => 'mykolaiv',
        'kharkiv_poltava' => 'kharkiv',
    ];

    public function handle(): int
    {
        $per = max(1, min(40, (int) $this->option('per-region')));
        $he = Language::where('code', 'he')->firstOrFail();
        $ru = Language::where('code', 'ru')->firstOrFail();
        $ua = Manufacturer::query()->where('code', 'UA')->firstOrFail();

        $this->ensureLabels($he, $ru);

        if ($this->option('fresh')) {
            $ids = GoldenDay::query()
                ->where('signature', 'like', 'ua-gd-%')
                ->pluck('id');
            $this->warn('Deleting '.$ids->count().' UA golden days…');
            GoldenDay::query()->whereIn('id', $ids)->delete();
        }

        $regionCfg = (array) config('travel_regions.regions.UA', []);
        $created = 0;

        foreach ($regionCfg as $regionKey => $row) {
            $category = Category::query()
                ->whereHas('descriptions', fn ($q) => $q->where('name', $row['ru']))
                ->first();
            if (! $category) {
                $this->error('Region category missing: '.$row['ru']);

                continue;
            }

            $locs = Location::query()
                ->with('descriptions')
                ->where('manufacturer_id', $ua->id)
                ->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id))
                ->orderByDesc('weight')
                ->orderBy('id')
                ->get();

            if ($locs->count() < 2) {
                $this->warn($row['ru'].': too few locations ('.$locs->count().')');

                continue;
            }

            $hub = $this->hubByRegion[$regionKey] ?? $regionKey;
            $plans = $this->dayPlans($locs, $per, $row['ru'], $hub, (int) $ru->id, (int) $he->id);
            $n = 0;
            $seenStops = [];
            foreach ($plans as $planIdx => $plan) {
                $stopFp = implode(',', collect($plan['loc_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all());
                if ($stopFp !== '' && isset($seenStops[$stopFp])) {
                    continue; // тот же набор стопов уже есть — не плодим дубли вроде #10028/#10040
                }
                $seenStops[$stopFp] = true;
                $sig = 'ua-gd-'.$regionKey.'-'.substr(sha1($plan['kind'].'|'.$stopFp.'|'.($plan['load'] ?? '')), 0, 16);
                if (GoldenDay::query()->where('signature', $sig)->exists()) {
                    continue;
                }

                DB::transaction(function () use ($plan, $sig, $category, $hub, &$created, &$n) {
                    $day = GoldenDay::create([
                        'title' => $plan['title_he'],
                        'title_ru' => $plan['title_ru'],
                        'category_id' => $category->id,
                        'hub_key' => $hub,
                        'search_blob' => mb_strtolower($plan['title_ru'].' '.$hub.' '.implode(' ', $plan['names'])),
                        'start_point' => $plan['names'][0] ?? null,
                        'start_point_ru' => $plan['names'][0] ?? null,
                        'end_point' => $plan['names'][count($plan['names']) - 1] ?? null,
                        'end_point_ru' => $plan['names'][count($plan['names']) - 1] ?? null,
                        'signature' => $sig,
                        'stops_count' => count($plan['loc_ids']),
                        'usage_count' => 1,
                        'is_active' => true,
                        'meta' => [
                            'seed' => 'ua_golden_v1',
                            'region' => $plan['region_ru'],
                            'kind' => $plan['kind'],
                            'load' => $plan['load'] ?? null,
                        ],
                        'ru_units' => ['title' => true, 'stops' => true],
                    ]);

                    foreach ($plan['loc_ids'] as $i => $locId) {
                        $loc = $plan['locs'][$i];
                        GoldenDayStop::create([
                            'golden_day_id' => $day->id,
                            'sort_order' => $i,
                            'location_id' => $locId,
                            'source_model' => $loc->model,
                            'name' => $plan['names_he'][$i] ?? $plan['names'][$i],
                            'name_ru' => $plan['names'][$i],
                        ]);
                    }

                    $labelIds = GoldenLabel::query()
                        ->whereIn('slug', $plan['labels'])
                        ->pluck('id')
                        ->all();
                    $day->labels()->sync($labelIds);
                    $created++;
                    $n++;
                });
            }

            $this->line($row['ru'].': +'.$n.' days (hub='.$hub.')');
        }

        $this->info("Done. UA golden days created this run: {$created}");
        $this->info('Total ua-gd-* now: '.GoldenDay::query()->where('signature', 'like', 'ua-gd-%')->count());

        return self::SUCCESS;
    }

    private function ensureLabels(Language $he, Language $ru): void
    {
        $defs = [
            'hub-lviv' => ['Львов (база)', 'לבוב (בסיס)'],
            'hub-carpathians' => ['Карпаты (база)', 'קרפטים (בסיס)'],
            'hub-uzhhorod' => ['Ужгород (база)', 'אוז׳הורוד (בסיס)'],
            'hub-kyiv' => ['Киев (база)', 'קייב (בסיס)'],
            'hub-uman' => ['Умань (база)', 'אומן (בסיס)'],
            'hub-odesa' => ['Одесса (база)', 'אודסה (בסיס)'],
            'hub-mykolaiv' => ['Николаев (база)', 'מיקולאייב (בסיס)'],
            'hub-kharkiv' => ['Харьков (база)', 'חרקוב (בסיס)'],
            'stay' => ['День у базы', 'יום ליד הבסיס'],
            'transfer' => ['Переезд', 'מעבר'],
            'nature' => ['Природа', 'טבע'],
            'family' => ['С детьми', 'עם ילדים'],
            'no-kids' => ['Без детей', 'ללא ילדים'],
            'arrival' => ['Въезд', 'הגעה'],
            'departure' => ['Выезд', 'עזיבה'],
            'flight' => ['Перелёт', 'טיסה'],
            'plane' => ['Самолёт', 'מטוס'],
            'bus' => ['Автобус', 'אוטובוס'],
            'car' => ['Автомобиль', 'רכב'],
            'kosher-friendly' => ['Кошер-friendly', 'ידידותי לכשר'],
            'city' => ['Город / культура', 'עיר / תרבות'],
            'soft' => ['1 · Лёгкий', '1 · קל'],
            'medium' => ['2 · Средний', '2 · בינוני'],
            'hard' => ['3 · Насыщенный', '3 · עמוס'],
            'short-day' => ['Короткий / полдня', 'קצר / חצי יום'],
            'mountains' => ['Горы', 'הרים'],
            'food' => ['Еда / гастро', 'אוכל / גסטרו'],
            'active' => ['Активный отдых', 'פעילות קלה'],
            'active-trek' => ['Активный трек', 'טרק פעיל'],
        ];

        $sort = 200;
        foreach ($defs as $slug => [$nameRu, $nameHe]) {
            $label = GoldenLabel::query()->firstOrCreate(
                ['slug' => $slug],
                ['sort_order' => $sort++, 'status' => true]
            );
            GoldenLabelDescription::updateOrCreate(
                ['golden_label_id' => $label->id, 'language_id' => $ru->id],
                ['name' => $nameRu]
            );
            GoldenLabelDescription::updateOrCreate(
                ['golden_label_id' => $label->id, 'language_id' => $he->id],
                ['name' => $nameHe]
            );
        }
    }

    /**
     * @param  Collection<int, Location>  $locs
     * @return list<array<string, mixed>>
     */
    private function dayPlans($locs, int $per, string $regionRu, string $hub, int $ruId, int $heId): array
    {
        $heavy = $locs->filter(fn (Location $l) => (int) $l->weight >= 6)->values();
        if ($heavy->count() < 3) {
            $heavy = $locs->take(min(40, $locs->count()))->values();
        }

        [$hubLat, $hubLng, $cityRadiusKm] = $this->hubGeo($hub);
        // День у базы = только точки рядом с хабом (без Вышгорода↔Пирогово↔Чернигова в одном дне).
        $remoteNameRe = match ($hub) {
            'lviv' => '/Олесск|Подгорец|Золочев|Жовкв|Унив|Трускавец|Винников/ui',
            'kyiv' => '/Чернигов|Белая Церковь|Александрия|Межигор|Вышгород|Пирогов/ui',
            'odesa' => '/Вилков|Аккерман|Белгород-Днестров|Затока|Коблево|Шабо/ui',
            'carpathians' => '/Говерл/ui',
            default => null,
        };
        $city = $locs->filter(function (Location $l) use ($hubLat, $hubLng, $cityRadiusKm, $remoteNameRe, $ruId) {
            $name = (string) ($l->descriptions->firstWhere('language_id', $ruId)?->name
                ?? data_get($l->meta, 'title', ''));
            if ($remoteNameRe && preg_match($remoteNameRe, $name)) {
                return false;
            }
            $w = (int) ($l->weight ?? 0);
            if ($w < 5 && ! preg_match('/хаб|Киев|Львов|Одесс|Ужгород|Харьков|Ивано|Славськ/ui', $name.' '.((string) data_get($l->meta, 'place_type', '')))) {
                return false;
            }
            $lat = (float) ($l->lat ?? 0);
            $lng = (float) ($l->lng ?? 0);
            if ($lat && $lng && $hubLat && $hubLng) {
                return $this->haversineKm($hubLat, $hubLng, $lat, $lng) <= $cityRadiusKm;
            }

            return $w >= 6;
        })->values();
        if ($city->count() < 3) {
            $city = $heavy->filter(function (Location $l) use ($hubLat, $hubLng, $cityRadiusKm) {
                $lat = (float) ($l->lat ?? 0);
                $lng = (float) ($l->lng ?? 0);
                if (! $lat || ! $lng || ! $hubLat || ! $hubLng) {
                    return true;
                }

                return $this->haversineKm($hubLat, $hubLng, $lat, $lng) <= $cityRadiusKm * 1.4;
            })->values();
        }
        if ($city->count() < 3) {
            $city = $heavy;
        }
        $outing = $locs->filter(function (Location $l) use ($city, $remoteNameRe, $ruId) {
            if ($city->contains('id', $l->id)) {
                return false;
            }
            $name = (string) ($l->descriptions->firstWhere('language_id', $ruId)?->name
                ?? data_get($l->meta, 'title', ''));
            if ($remoteNameRe && preg_match($remoteNameRe, $name)) {
                return true;
            }

            return (int) ($l->weight ?? 0) >= 5;
        })->values();
        $nature = $locs->filter(fn (Location $l) => str_contains((string) data_get($l->meta, 'place_type'), 'природ')
            || str_contains((string) data_get($l->meta, 'place_type'), 'актив'))->values();
        $natureCity = $nature->filter(fn (Location $l) => $city->contains('id', $l->id))->values();
        if ($natureCity->count() >= 2) {
            $nature = $natureCity;
        }
        // Говерла и пики — в пул nature Карпат (даже если вне «городского» кластера)
        if ($hub === 'carpathians') {
            $trekLocs = $locs->filter(function (Location $l) {
                $t = mb_strtolower((string) data_get($l->meta, 'title', ''));

                return str_contains($t, 'говерл') || str_contains($t, 'петрос')
                    || str_contains($t, 'трек') || str_contains($t, 'перевал');
            });
            if ($trekLocs->isNotEmpty()) {
                $nature = $trekLocs->concat($nature)->unique('id')->values();
            }
        }
        $family = $locs->filter(fn (Location $l) => (bool) data_get($l->meta, 'family'))->values();
        $familyCity = $family->filter(fn (Location $l) => $city->contains('id', $l->id))->values();
        if ($familyCity->count() >= 2) {
            $family = $familyCity;
        }
        $kosher = $locs->filter(fn (Location $l) => ($l->kosher_food ?? '') === 'yes')->values();
        $kosherCity = $kosher->filter(fn (Location $l) => $city->contains('id', $l->id))->values();
        if ($kosherCity->count() >= 2) {
            $kosher = $kosherCity;
        }
        $locs = $locs->values();

        $plans = [];
        $usedStopSets = [];
        // i=0 soft arrival, i=1 medium arrival («лёгкий+» / средний по приезду), last = departure soft.
        $kinds = ['stay', 'stay', 'nature', 'family', 'transfer', 'stay', 'nature', 'kosher'];

        for ($i = 0; $i < $per; $i++) {
            $kind = $kinds[$i % count($kinds)];
            $load = null;
            if ($i === 0) {
                $kind = 'arrival';
                $load = 'soft';
            } elseif ($i === 1 && $per > 3) {
                $kind = 'arrival';
                $load = 'medium';
            } elseif ($per > 1 && $i === $per - 1) {
                $kind = 'departure';
                $load = 'soft';
            }
            $light = $locs->filter(function (Location $l) {
                $w = (int) ($l->weight ?? 5);
                $type = (string) data_get($l->meta, 'place_type', '');
                $act = (string) data_get($l->meta, 'activity', '');

                return $w <= 4 && ! str_contains($type, 'хаб') && ! str_contains($act, 'актив');
            })->values();
            $food = $locs->filter(fn (Location $l) => str_contains((string) data_get($l->meta, 'place_type', ''), 'еда'))->values();
            $mid = $locs->filter(function (Location $l) {
                $w = (int) ($l->weight ?? 5);
                $type = (string) data_get($l->meta, 'place_type', '');

                return $w >= 5 && $w <= 8 && ! str_contains($type, 'хаб');
            })->values();

            $stayPool = $city;
            $pool = match ($kind) {
                'nature' => $nature->isNotEmpty() ? $nature : $stayPool,
                'family' => $family->isNotEmpty() ? $family : $stayPool,
                'kosher' => $kosher->count() >= 2 ? $kosher : $stayPool,
                'transfer' => $outing->count() >= 2 ? $outing : $heavy,
                'arrival', 'departure' => ($food->isNotEmpty() ? $food->concat($light) : $light)
                    ->filter(fn (Location $l) => $city->contains('id', $l->id) || (int) ($l->weight ?? 0) <= 4)
                    ->unique('id')->values()
                    ->whenEmpty(fn () => $city),
                default => $stayPool,
            };

            $take = ($kind === 'arrival' || $kind === 'departure') ? 2 : 3;
            if ($kind === 'arrival' && $load === 'medium') {
                $take = 3;
                $pool = ($nature->isNotEmpty() ? $nature->concat($mid) : $mid->concat($stayPool))
                    ->filter(fn (Location $l) => $city->contains('id', $l->id) || $stayPool->contains('id', $l->id))
                    ->unique('id')->values()->whenEmpty(fn () => $stayPool);
            }

            // День у базы — плотный кластер; дальние точки только в transfer.
            $clusterKm = match (true) {
                $kind === 'transfer' => 90.0,
                $hub === 'carpathians' => 18.0,
                default => min(8.0, $cityRadiusKm),
            };

            $pick = null;
            $maxTries = max(8, $pool->count());
            for ($try = 0; $try < $maxTries; $try++) {
                $seedIdx = ($i * 3 + $try * 2) % max(1, $pool->count());
                $candidate = null;

                if (($kind === 'arrival' || $kind === 'departure') && $load !== 'medium') {
                    // Въезд в центр города: Babel Fish / кафе + прогулка (не устричная ферма)
                    $cityFood = $food->filter(function (Location $l) use ($city) {
                        $name = mb_strtolower($l->nameFor());
                        $type = mb_strtolower((string) data_get($l->meta, 'place_type', ''));
                        if (str_contains($type, 'выездн') || str_contains($name, 'устрич') || str_contains($name, 'тест')) {
                            return false;
                        }

                        return $city->contains('id', $l->id)
                            || str_contains($name, 'babel') || str_contains($name, 'бабель');
                    })->values();
                    $cafe = $cityFood->first(fn (Location $l) => str_contains(mb_strtolower($l->nameFor()), 'babel')
                        || str_contains(mb_strtolower($l->nameFor()), 'бабель'))
                        ?? $cityFood->values()->get(($i + $try) % max(1, max(1, $cityFood->count())))
                        ?? $food->first()
                        ?? $pool->get($seedIdx);
                    $walkPool = $locs->filter(function (Location $l) use ($city) {
                        $name = mb_strtolower($l->nameFor());
                        $type = mb_strtolower((string) data_get($l->meta, 'place_type', ''));
                        if (str_contains($type, 'выездн') || str_contains($name, 'устрич')) {
                            return false;
                        }

                        return $city->contains('id', $l->id)
                            && (str_contains($name, 'маразлиев') || str_contains($name, 'шевченк')
                                || str_contains($name, 'синагог') || str_contains($type, 'по дороге')
                                || str_contains($name, 'прогул'));
                    })->values();
                    $nearPool = $walkPool->isNotEmpty()
                        ? $walkPool->concat($light)->unique('id')->values()
                        : ($light->isNotEmpty() ? $light : $pool);
                    $takeSoft = ($hub === 'odesa') ? 3 : 2;
                    $nearFood = $this->pickCluster($cafe, $nearPool, $takeSoft, min(8.0, $clusterKm));
                    if ($hub === 'odesa' && $nearFood->count() < 3 && $nearPool->count() >= 2) {
                        $nearFood = collect([$cafe])->concat($nearPool)->unique('id')->take(3)->values();
                    }
                    $candidate = $nearFood->count() >= 2 ? $nearFood : collect([$cafe, $nearPool->first()])->filter()->unique('id')->values();
                } elseif ($kind === 'arrival' && $load === 'medium') {
                    // Средний въезд: хаб + синагоги / Babel / центр (не дальние outing)
                    $hubLoc = $locs->first(fn (Location $l) => str_contains((string) data_get($l->meta, 'place_type', ''), 'хаб'))
                        ?? $stayPool->first()
                        ?? $locs->first();
                    $extrasPool = $stayPool->concat($mid)->filter(function (Location $l) {
                        $name = mb_strtolower($l->nameFor());
                        $type = mb_strtolower((string) data_get($l->meta, 'place_type', ''));

                        return ! str_contains($type, 'выездн') && ! str_contains($name, 'устрич')
                            && ! str_contains($name, 'вилков') && ! str_contains($name, 'заток');
                    })->unique('id')->values();
                    $candidate = $this->pickCluster($hubLoc, $extrasPool, 3, $clusterKm);
                } elseif ($kind === 'transfer') {
                    $transferPool = $outing->count() >= 2 ? $outing : $heavy;
                    $seed = $transferPool->get(($i + $try) % max(1, $transferPool->count())) ?? $pool->get($seedIdx);
                    $candidate = $this->pickCluster($seed, $transferPool->concat($locs)->unique('id')->values(), 3, $clusterKm);
                } else {
                    $seed = $pool->get($seedIdx) ?? $pool->first();
                    $candidate = $this->pickCluster($seed, $pool, $take, $clusterKm);
                    if ($candidate->count() < 2) {
                        // Не расползаться по всей области — только stayPool/city.
                        $candidate = $this->pickCluster($seed, $stayPool->concat($pool)->unique('id')->values(), $take, $clusterKm * 1.25);
                    }
                }

                $candidate = $this->orderNearestNeighbor($candidate->filter()->unique('id')->values());
                if ($candidate->count() < 2) {
                    continue;
                }
                // Зигзаг «центр → село на север → село на юго-запад» отсекаем (кроме длинного переезда).
                if ($kind !== 'transfer' && $this->maxHopKm($candidate) > $clusterKm * 1.25) {
                    continue;
                }

                $fp = $candidate->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->implode(',');
                if ($fp === '' || isset($usedStopSets[$fp])) {
                    continue;
                }
                $usedStopSets[$fp] = true;
                $pick = $candidate;
                break;
            }
            if (! $pick) {
                continue; // не удалось собрать уникальный компактный набор
            }

            // Карпаты · nature: хотя бы один день с Говерлой / пиком, если локация есть в каталоге
            if ($hub === 'carpathians' && $kind === 'nature') {
                $trekSeed = $locs->first(function (Location $l) {
                    $t = mb_strtolower((string) data_get($l->meta, 'title', ''));

                    return str_contains($t, 'говерл') || str_contains($t, 'петрос');
                });
                if ($trekSeed && ! $pick->contains('id', $trekSeed->id)) {
                    $alreadyHasTrekDay = collect($plans)->contains(function (array $p) use ($trekSeed) {
                        return in_array((int) $trekSeed->id, array_map('intval', $p['loc_ids'] ?? []), true);
                    });
                    if (! $alreadyHasTrekDay) {
                        $pick = $pick->take(max(1, $pick->count() - 1))->prepend($trekSeed)->unique('id')->values();
                        $pick = $this->orderNearestNeighbor($pick);
                    }
                }
            }

            $names = [];
            $namesHe = [];
            $locIds = [];
            $locModels = [];
            foreach ($pick as $loc) {
                $locIds[] = $loc->id;
                $locModels[] = $loc;
                $ruName = $loc->descriptions->firstWhere('language_id', $ruId)?->name
                    ?? $loc->nameFor();
                $heName = $loc->descriptions->firstWhere('language_id', $heId)?->name
                    ?? $ruName;
                $names[] = $ruName;
                $namesHe[] = $heName;
            }

            $labels = ['hub-'.$hub];
            $labels[] = match ($kind) {
                'transfer' => 'transfer',
                'nature' => 'nature',
                'family' => 'family',
                'kosher' => 'kosher-friendly',
                'arrival' => 'arrival',
                'departure' => 'departure',
                default => 'stay',
            };
            if ($kind === 'kosher') {
                $labels[] = 'stay';
            }
            if ($kind === 'arrival' || $kind === 'departure') {
                $labels[] = $load === 'medium' ? 'medium' : 'soft';
                if ($load !== 'medium') {
                    $labels[] = 'short-day';
                }
                if ($hub === 'carpathians') {
                    $labels[] = 'mountains';
                    $labels[] = 'nature';
                } elseif ($hub === 'lviv' || $hub === 'kyiv' || $hub === 'uzhhorod' || $hub === 'kharkiv') {
                    $labels[] = 'city';
                }
                if ($load !== 'medium') {
                    $labels[] = 'food';
                }
            }
            if ($hub === 'lviv' && in_array($kind, ['stay', 'family', 'nature', 'kosher'], true)) {
                $labels[] = 'city';
            }

            // Активный отдых vs активный трек (Карпаты и по названиям стопов)
            if (! in_array($kind, ['arrival', 'departure'], true)) {
                $blob = mb_strtolower(implode(' ', $names));
                $softActive = preg_match('/буковель|тростян|подъёмник|подъемник|лыж|сноуборд|каяк|сплав|верёвоч/u', $blob);
                $hardTrek = preg_match('/говерл|петрос|восхожд|перевал|хайк|трек/u', $blob)
                    || ($hub === 'carpathians' && $kind === 'nature' && ! $softActive);
                if ($hardTrek) {
                    $labels[] = 'active-trek';
                    $labels[] = 'no-kids';
                } elseif ($softActive) {
                    $labels[] = 'active';
                }
            }

            $shortNames = array_map(fn (string $n) => preg_replace('/^Львов\s*[—\-]\s*/u', '', $n) ?? $n, $names);
            $titleRu = match ($kind) {
                'transfer' => $regionRu.' · выезд: '.$shortNames[0].' → '.$shortNames[count($shortNames) - 1],
                'nature' => $regionRu.' · '.$shortNames[0].(isset($shortNames[1]) ? ' + '.$shortNames[1] : ''),
                'family' => $regionRu.' · '.$shortNames[0].(isset($shortNames[1]) ? ' + '.$shortNames[1] : ''),
                'kosher' => $regionRu.' · кошер: '.$shortNames[0],
                'arrival' => $load === 'medium'
                    ? $regionRu.' · прилёт / въезд (средний): '.$shortNames[0].(isset($shortNames[1]) ? ' + '.$shortNames[1] : '')
                    : $regionRu.' · прилёт / въезд (лёгкий): '.$shortNames[0],
                'departure' => $regionRu.' · выезд: '.$shortNames[0],
                default => $regionRu.' · '.$shortNames[0].(isset($shortNames[1]) ? ' + '.$shortNames[1] : ''),
            };

            $plans[] = [
                'kind' => $kind,
                'load' => $load,
                'region_ru' => $regionRu,
                'title_ru' => $titleRu,
                'title_he' => $titleRu,
                'loc_ids' => $locIds,
                'locs' => $locModels,
                'names' => $names,
                'names_he' => $namesHe,
                'labels' => $labels,
            ];
        }

        return $plans;
    }

    /**
     * @return array{0: float, 1: float, 2: float} lat, lng, city radius km
     */
    private function hubGeo(string $hub): array
    {
        return match ($hub) {
            'lviv' => [49.8419, 24.0315, 10.0],
            'kyiv' => [50.4500, 30.5233, 10.0],
            'odesa' => [46.4845, 30.7326, 10.0],
            'uzhhorod' => [48.6208, 22.2879, 12.0],
            'kharkiv' => [50.0040, 36.2310, 10.0],
            'carpathians' => [48.4580, 24.5560, 20.0], // Яремче как горный якорь
            'uman' => [48.7480, 30.2200, 15.0],
            'mykolaiv' => [46.9750, 31.9900, 15.0],
            default => [0.0, 0.0, 12.0],
        };
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Набрать точки рядом с якорем (без разброса по сёлам в разные стороны).
     *
     * @param  Collection<int, Location>  $pool
     * @return Collection<int, Location>
     */
    private function pickCluster(?Location $seed, $pool, int $take, float $maxKm)
    {
        $pool = collect($pool)->filter()->unique('id')->values();
        if (! $seed && $pool->isNotEmpty()) {
            $seed = $pool->first();
        }
        if (! $seed) {
            return collect();
        }
        $picked = collect([$seed]);
        $rest = $pool->filter(fn (Location $l) => $l->id !== $seed->id)->values();
        while ($picked->count() < $take && $rest->isNotEmpty()) {
            $last = $picked->last();
            $best = null;
            $bestKm = INF;
            foreach ($rest as $loc) {
                $km = $this->locKm($last, $loc);
                if ($km <= $maxKm && $km < $bestKm) {
                    $bestKm = $km;
                    $best = $loc;
                }
            }
            if (! $best) {
                break;
            }
            $picked->push($best);
            $rest = $rest->filter(fn (Location $l) => $l->id !== $best->id)->values();
        }

        return $picked->values();
    }

    /**
     * @param  Collection<int, Location>  $locs
     * @return Collection<int, Location>
     */
    private function orderNearestNeighbor($locs)
    {
        $locs = collect($locs)->filter()->values();
        if ($locs->count() <= 1) {
            return $locs;
        }
        $remaining = $locs->all();
        $ordered = [array_shift($remaining)];
        while ($remaining !== []) {
            $last = $ordered[count($ordered) - 1];
            $bestIdx = 0;
            $bestKm = INF;
            foreach ($remaining as $i => $loc) {
                $km = $this->locKm($last, $loc);
                if ($km < $bestKm) {
                    $bestKm = $km;
                    $bestIdx = $i;
                }
            }
            $ordered[] = $remaining[$bestIdx];
            array_splice($remaining, $bestIdx, 1);
        }

        return collect($ordered)->values();
    }

    /**
     * @param  Collection<int, Location>  $locs
     */
    private function maxHopKm($locs): float
    {
        $locs = collect($locs)->values();
        $max = 0.0;
        for ($i = 0; $i < $locs->count() - 1; $i++) {
            $max = max($max, $this->locKm($locs[$i], $locs[$i + 1]));
        }

        return $max;
    }

    private function locKm(Location $a, Location $b): float
    {
        $lat1 = (float) ($a->lat ?? 0);
        $lng1 = (float) ($a->lng ?? 0);
        $lat2 = (float) ($b->lat ?? 0);
        $lng2 = (float) ($b->lng ?? 0);
        if (! $lat1 || ! $lng1 || ! $lat2 || ! $lng2) {
            return 9999.0;
        }

        return $this->haversineKm($lat1, $lng1, $lat2, $lng2);
    }
}
