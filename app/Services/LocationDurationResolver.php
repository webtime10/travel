<?php

namespace App\Services;

use App\Models\Location;

/**
 * Единый источник длительности визита: факты каталога → эвристика по имени.
 * Используют LocationDayPacker и LocationPlanValidator.
 */
class LocationDurationResolver
{
    /** @var array<int, array{hours: float, source: string, multi_day?: bool}> */
    private array $cache = [];

    /**
     * @param  list<int>  $ids
     * @return array<int, array{hours: float, source: string, multi_day?: bool}>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $missing = array_values(array_filter($ids, fn (int $id) => ! isset($this->cache[$id])));
        if ($missing !== []) {
            $this->loadMissing($missing);
        }
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->cache[$id] ?? $this->heuristicDuration('');
        }

        return $out;
    }

    /**
     * @return array{hours: float, source: string, multi_day?: bool}
     */
    public function resolve(int $id, ?string $fallbackName = null): array
    {
        if ($id > 0) {
            $map = $this->forIds([$id]);
            if (isset($map[$id])) {
                return $map[$id];
            }
        }

        return $this->heuristicDuration((string) $fallbackName);
    }

    public function hoursForId(int $id, ?string $fallbackName = null): float
    {
        $r = $this->resolve($id, $fallbackName);

        return ! empty($r['multi_day']) ? 8.0 : (float) ($r['hours'] ?? 1.5);
    }

    public function hoursForName(string $name): float
    {
        return (float) ($this->heuristicDuration($name)['hours'] ?? 1.5);
    }

    public function isHeavyName(string $name): bool
    {
        return (bool) preg_match(
            '/tre cime|drei zinnen|3 פסגות|5 פסגות|три пик|тре-чиме|тре чиме|marmolada|מרמולדה|мармолад|busatte|בוסאטה|бузатте|cinq(ue)?\s*torri|5\s*אגמים|חמש\s*אגמים|пять оз/ui',
            $name
        );
    }

    /**
     * @param  list<int>  $ids
     */
    private function loadMissing(array $ids): void
    {
        $locs = Location::query()
            ->with(['facts.descriptions', 'descriptions'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $loc = $locs->get($id);
            $name = $loc?->descriptions->first()?->name
                ?? (string) (($loc?->meta['title'] ?? $loc?->meta_ru['title'] ?? $loc?->model ?? '') ?: '');
            if ($loc && $this->factsSayMultiDay($loc)) {
                $this->cache[$id] = [
                    'hours' => 0.0,
                    'source' => 'multi-day в фактах каталога',
                    'multi_day' => true,
                ];
                continue;
            }
            $parsed = $loc ? $this->parseDurationFromFacts($loc) : null;
            $heuristic = $this->heuristicDuration($name);
            if ($parsed !== null && $this->isHeavyName($name)) {
                if ($parsed['hours'] >= $heuristic['hours']) {
                    $this->cache[$id] = $parsed;
                } else {
                    $this->cache[$id] = [
                        'hours' => $heuristic['hours'],
                        'source' => $heuristic['source'].'; факт давал '.$parsed['hours'].'h — взято max',
                    ];
                }
                continue;
            }
            // Для packing/validator: если факт дал range min–max, parse берёт min;
            // для heavy уже обработали. Для обычных — факт приоритетнее.
            $this->cache[$id] = $parsed ?? $heuristic;
        }
    }

    private function factsSayMultiDay(Location $loc): bool
    {
        foreach ($loc->facts as $fact) {
            foreach ($fact->descriptions as $d) {
                $blob = (string) ($d->name ?? '').' '.(string) ($d->text ?? '').' '.(string) ($d->hours ?? '');
                if (preg_match('/יומיים|שני ימים|two[- ]?day|2[- ]?day|двухднев|на 2 дня|לשני ימים/ui', $blob)) {
                    return true;
                }
            }
        }
        $name = (string) ($loc->descriptions->first()?->name ?? '');

        return (bool) preg_match('/יומיים|שני ימים|two[- ]?day|двухднев/ui', $name);
    }

    /**
     * @return array{hours: float, source: string}|null
     */
    private function parseDurationFromFacts(Location $loc): ?array
    {
        $bestMin = null;
        $bestMax = null;
        $bestBlob = null;
        $bestGroup = null;

        foreach ($loc->facts as $fact) {
            $group = (string) ($fact->group ?? '');
            foreach ($fact->descriptions as $d) {
                $blob = trim((string) ($d->hours ?? '').' '.(string) ($d->name ?? '').' '.(string) ($d->text ?? ''));
                if ($blob === '') {
                    continue;
                }
                if ($group !== 'time' && $group !== 'activity' && $group !== 'attraction'
                    && ! preg_match('/שעות|hours?|час|дн[ея]|half.?day|full.?day|משך|duration|duration_min|duration_max/ui', $blob)) {
                    continue;
                }
                $range = $this->extractHoursRange($blob);
                if ($range === null) {
                    continue;
                }
                [$minH, $maxH] = $range;
                if ($minH <= 0 || $minH > 16) {
                    continue;
                }
                // Берём самый «полный» range (больший max), иначе первый валидный
                if ($bestMax === null || $maxH > $bestMax) {
                    $bestMin = $minH;
                    $bestMax = $maxH;
                    $bestBlob = $blob;
                    $bestGroup = $group;
                }
            }
        }

        if ($bestMin === null || $bestMax === null) {
            return null;
        }
        // Для бюджета дня используем верхнюю оценку (не занижать трек)
        $hours = $bestMax;

        return [
            'hours' => $hours,
            'source' => 'факт group='.$bestGroup.' '.$bestMin.'–'.$bestMax.'h «'.mb_substr((string) $bestBlob, 0, 72).'»',
        ];
    }

    /**
     * @return array{0: float, 1: float}|null [min, max]
     */
    private function extractHoursRange(string $blob): ?array
    {
        if (preg_match('/(\d+[.,]?\d*)\s*[-–—]\s*(\d+[.,]?\d*)\s*(שעות|hours?|ч(ас(а|ов)?)?)/ui', $blob, $m)) {
            $a = (float) str_replace(',', '.', $m[1]);
            $b = (float) str_replace(',', '.', $m[2]);

            return [min($a, $b), max($a, $b)];
        }
        if (preg_match('/(\d+[.,]?\d*)\s*(שעות|hours?|ч(ас(а|ов)?)?)/ui', $blob, $m)) {
            $h = (float) str_replace(',', '.', $m[1]);

            return [$h, $h];
        }
        if (preg_match('/half\s*day|חצי\s*יום|полдня/ui', $blob)) {
            return [4.0, 4.0];
        }
        if (preg_match('/full\s*day|יום\s*שלם|целый\s*день/ui', $blob)) {
            return [7.0, 7.0];
        }
        if (preg_match('/(\d+)\s*(дн|days?|ימים)/ui', $blob, $m) && (int) $m[1] >= 2) {
            return null;
        }

        return null;
    }

    /**
     * @return array{hours: float, source: string}
     */
    public function heuristicDuration(string $name): array
    {
        $default = (float) config('travel_validator.default_duration_hours', 1.5);
        $n = mb_strtolower($name);
        if (preg_match('/tre cime|drei zinnen|3 פסגות|5 פסגות|три пик|тре-чиме|тре чиме|פסגות דולומיטים/ui', $n)) {
            return ['hours' => 5.0, 'source' => 'оценка по названию (крупный пеший маршрут), в фактах visit duration нет'];
        }
        if (preg_match('/cinq(ue)?\s*torri|чинкве\s*торри/ui', $n)) {
            return ['hours' => 4.0, 'source' => 'оценка по названию (Cinque Torri), в фактах visit duration нет'];
        }
        if (preg_match('/5\s*אגמים|חמש\s*אגמים|cinq(ue)?\s*lagh|пять оз|5\s*озёр|5\s*озер/ui', $n)) {
            return ['hours' => 5.0, 'source' => 'оценка по названию (трек 5 озёр), в фактах visit duration нет'];
        }
        if (preg_match('/marmolada|מרמולדה|мармолад/ui', $n)) {
            return ['hours' => 4.0, 'source' => 'оценка по названию, в фактах visit duration нет'];
        }
        if (preg_match('/busatte|בוסאטה|бузатте/ui', $n)) {
            return ['hours' => 3.0, 'source' => 'оценка по названию, в фактах visit duration нет'];
        }
        // Рафтинг / каньонинг — обычно half/full day; не добивать вторым POI
        if (preg_match('/raft|ראפט|рафтинг|canyon|קניון|каньон|bueno\s*canyon/ui', $n)) {
            return ['hours' => 5.0, 'source' => 'оценка по названию (рафтинг/каньонинг, программа дня), в фактах visit duration нет'];
        }
        if (preg_match('/ponale|פונל|понал/ui', $n)) {
            return ['hours' => 2.5, 'source' => 'оценка по названию (панорамный маршрут), в фактах visit duration нет'];
        }
        if (preg_match('/שייט|boat|катер|ferry/ui', $n)) {
            return ['hours' => 1.0, 'source' => 'оценка по названию, в фактах visit duration нет'];
        }
        if (preg_match('/מסלול|hike|טיול רגלי|via ferrata|феррат|ferrato/ui', $n)) {
            return ['hours' => 3.0, 'source' => 'оценка по названию (пеший маршрут), в фактах visit duration нет'];
        }
        // Короткий водопад — остановка, не полтора часа программы
        if (preg_match('/waterfall|מפל|водопад|cascata|nardis|נארדיס|нардис|varone|וארנה|вароне|riva\s*fall|cascate\s*di\s*riva/ui', $n)) {
            return ['hours' => 0.5, 'source' => 'оценка: короткий водопад ~0.5 ч'];
        }
        // Верёвочный / adventure park — обычно ~3 ч, не 1.5
        if (preg_match('/rope|חבלים|веревоч|adventure\s*park|emozion|col\s*de\s*flam|high\s*rope/ui', $n)) {
            return ['hours' => 3.0, 'source' => 'оценка: верёвочный/парк приключений ~3 ч'];
        }
        // Смотровая / belvedere — коротко
        if (preg_match('/belvedere|смотров|viewpoint|panoramic\s*point|תצפית/ui', $n)
            && ! preg_match('/hike|trek|מסלול| lag/ui', $n)) {
            return ['hours' => 0.5, 'source' => 'оценка: смотровая ~0.5 ч'];
        }
        if (preg_match('/carezza|קארצה|карецц/ui', $n)) {
            return ['hours' => 2.5, 'source' => 'оценка по названию (Carezza), в фактах visit duration нет'];
        }
        // Озеро — заложить время на прогулку/отдых у воды, не «миссия 1.5ч»
        if (preg_match('/lago|אגם|озеро|braies|בראייס|tovel|טובל|ledro|לדרו|misurina|מיזורינה|molveno|מולבנו|dobbiaco|דוביאקו|tenno|טנו|тенн|tobl|тобл/ui', $n)) {
            return ['hours' => 2.0, 'source' => 'оценка: время на озере ~2 ч'];
        }

        return ['hours' => $default, 'source' => 'длительность по умолчанию '.$default.' ч (в фактах visit duration нет)'];
    }
}
