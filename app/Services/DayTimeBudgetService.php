<?php

namespace App\Services;

/**
 * Прикидка «на что уходит день»: POI + дорога + обед + логистика + база.
 * Не меняет places; только обогащает day.time_budget для UI.
 */
class DayTimeBudgetService
{
    public function __construct(private GeoDistance $geo) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $pois
     * @return array<string, mixed>
     */
    public function enrichPlan(array $plan, array $pois): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return $plan;
        }
        $byId = [];
        foreach ($pois as $p) {
            if (is_array($p) && (int) ($p['id'] ?? 0) > 0) {
                $byId[(int) $p['id']] = $p;
            }
        }
        $prevOid = null;
        foreach ($plan['days'] as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $plan['days'][$i]['time_budget'] = $this->budgetForDay($day, $byId, $prevOid);
            $oid = (int) ($day['overnight']['id'] ?? 0);
            if ($oid > 0) {
                $prevOid = $oid;
            }
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, array<string, mixed>>  $byId
     * @return array<string, mixed>
     */
    private function budgetForDay(array $day, array $byId, ?int $prevOid): array
    {
        $theme = mb_strtolower((string) ($day['theme'] ?? ''));
        $kind = 'stay';
        if (str_contains($theme, 'заезд') || str_contains($theme, 'arrival')) {
            $kind = 'arrival';
        } elseif (str_contains($theme, 'выезд') || str_contains($theme, 'departure')) {
            $kind = 'departure';
        } elseif (str_contains($theme, 'переезд') || str_contains($theme, 'transfer')) {
            $kind = 'transfer';
        }

        $oid = (int) ($day['overnight']['id'] ?? 0);
        $overnight = $byId[$oid] ?? (is_array($day['overnight'] ?? null) ? $day['overnight'] : null);

        $poiH = 0.0;
        $baseH = 0.0;
        $stops = 0;
        $visitPts = [];
        foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pid = (int) ($p['id'] ?? 0);
            $role = (string) ($p['role'] ?? '');
            $h = isset($p['hours']) && is_numeric($p['hours']) ? (float) $p['hours'] : 0.0;
            if ($role === 'base' || ($pid > 0 && $pid === $oid)) {
                $baseH += max(0.0, $h);
                continue;
            }
            if ($h <= 0) {
                continue;
            }
            $poiH += $h;
            $stops++;
            $pt = $byId[$pid] ?? null;
            if ($pt !== null && isset($pt['lat'], $pt['lng'])) {
                $visitPts[] = $pt;
            }
        }

        $roadKm = $this->pathKm($kind, $overnight, $visitPts, $prevOid, $byId, $oid);
        $roadH = $this->roadHours($roadKm, $kind);
        $lunchH = $this->lunchHours($kind, $poiH, $stops);
        $logisticsH = $this->logisticsHours($kind, $stops, $poiH);
        if ($baseH <= 0 && in_array($kind, ['stay', 'arrival'], true)) {
            $baseH = $kind === 'arrival' ? 1.5 : 1.0;
        }
        if ($kind === 'departure') {
            $baseH = max($baseH, 0.5);
        }

        $total = round($poiH + $roadH + $lunchH + $logisticsH + $baseH, 1);
        $lines = [];
        if ($poiH > 0.05) {
            $lines[] = ['label' => 'Занятия у мест (POI)', 'hours' => round($poiH, 1)];
        }
        if ($roadH > 0.05) {
            $lines[] = [
                'label' => 'Дорога между точками (~'.round($roadKm, 0).' км с запасом)',
                'hours' => round($roadH, 1),
            ];
        }
        if ($lunchH > 0.05) {
            $lines[] = ['label' => 'Обед / кофе / пауза', 'hours' => round($lunchH, 1)];
        }
        if ($logisticsH > 0.05) {
            $lines[] = ['label' => 'Парковка, очереди, канатка/билеты', 'hours' => round($logisticsH, 1)];
        }
        if ($baseH > 0.05) {
            $baseLabel = $kind === 'arrival'
                ? 'База: заселение, прогулка по городку'
                : ($kind === 'departure'
                    ? 'Сборы / выезд с базы'
                    : 'База / свободное время у ночёвки');
            $lines[] = ['label' => $baseLabel, 'hours' => round($baseH, 1)];
        }

        $feel = 'ok';
        if ($kind === 'stay' && $total < 5.5) {
            $feel = 'thin';
        } elseif ($kind === 'stay' && $total > 11) {
            $feel = 'heavy';
        } elseif ($kind === 'transfer' && $total < 4) {
            $feel = 'thin';
        } elseif ($kind === 'transfer' && $total > 12) {
            $feel = 'heavy';
        } elseif (in_array($kind, ['arrival', 'departure'], true) && $total < 3) {
            $feel = 'light';
        }

        return [
            'kind' => $kind,
            'poi_hours' => round($poiH, 1),
            'road_km' => round($roadKm, 1),
            'road_hours' => round($roadH, 1),
            'lunch_hours' => round($lunchH, 1),
            'logistics_hours' => round($logisticsH, 1),
            'base_hours' => round($baseH, 1),
            'total_hours' => $total,
            'feel' => $feel,
            'lines' => $lines,
            'note' => $this->budgetNote($kind, $feel, $day),
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function budgetNote(string $kind, string $feel, array $day): string
    {
        $note = 'Прикидка PHP: занятия у мест + дорога (haversine×запас) + обед + логистика. Не GPS-маршрут.';
        $date = (string) ($day['date'] ?? '');
        if ($feel === 'heavy' && (str_contains($date, '15.08') || str_contains($date, '15/08'))) {
            $note .= ' Ferragosto (15.08): выезд лучше ≤08:00 — очереди на канатки/парковки.';
        } elseif ($feel === 'heavy' && $kind === 'stay') {
            $note .= ' День плотный: заложите ранний выезд и запас на серпантины.';
        } elseif ($feel === 'thin' && $kind === 'stay') {
            $note .= ' Stay тонкий: мало занятий — директору будет заметно.';
        }

        return $note;
    }

    /**
     * @param  array<string, mixed>|null  $overnight
     * @param  list<array<string, mixed>>  $visitPts
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function pathKm(
        string $kind,
        ?array $overnight,
        array $visitPts,
        ?int $prevOid,
        array $byId,
        int $oid,
    ): float {
        $pts = [];
        if ($kind === 'transfer' && $prevOid !== null && isset($byId[$prevOid])) {
            $pts[] = $byId[$prevOid];
        } elseif ($overnight !== null && isset($overnight['lat'], $overnight['lng'])) {
            $pts[] = $overnight;
        }
        foreach ($visitPts as $vp) {
            $pts[] = $vp;
        }
        if ($overnight !== null && isset($overnight['lat'], $overnight['lng'])) {
            // stay/arrival: возврат на базу; transfer: финиш на новой базе
            $pts[] = $overnight;
        }

        $km = 0.0;
        for ($i = 1; $i < count($pts); $i++) {
            $d = $this->geo->km($pts[$i - 1], $pts[$i]);
            if ($d !== null) {
                $km += $d;
            }
        }
        // Горные серпантины / объезды ≈ +40% к прямой
        $factor = $kind === 'transfer' ? 1.35 : 1.45;

        return $km * $factor;
    }

    private function roadHours(float $roadKm, string $kind): float
    {
        if ($roadKm < 1) {
            return 0.0;
        }
        // Горы ~35 км/ч среднее; длинный автобанный кусок на transfer чуть быстрее
        $speed = $kind === 'transfer' ? 48.0 : 35.0;
        $h = $roadKm / $speed;
        // Мин. кусок «выехали-приехали» если есть заметная дистанция
        if ($roadKm >= 8) {
            $h = max($h, 0.4);
        }

        return min(8.0, $h);
    }

    private function lunchHours(string $kind, float $poiH, int $stops): float
    {
        if ($kind === 'departure' && $poiH < 2.5) {
            return 0.5;
        }
        if ($kind === 'arrival' && $poiH < 1.5) {
            return 0.75;
        }
        if ($poiH + $stops <= 0) {
            return 0.5;
        }
        if ($kind === 'transfer') {
            return $poiH >= 1.5 ? 1.0 : 0.75;
        }

        return $poiH >= 3.0 ? 1.25 : 1.0;
    }

    private function logisticsHours(string $kind, int $stops, float $poiH): float
    {
        if ($stops <= 0) {
            return $kind === 'arrival' ? 0.3 : 0.0;
        }
        // Парковка + подход + ожидание канатки/билета на каждый stop
        $h = 0.35 * $stops;
        // Высокогорье / длинные POI — чуть больше очередей (август)
        if ($poiH >= 4.0) {
            $h += 0.4;
        } elseif ($poiH >= 2.0) {
            $h += 0.2;
        }

        return min(2.5, $h);
    }
}
