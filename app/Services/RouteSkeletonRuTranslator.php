<?php

namespace App\Services;

use App\Models\RouteSkeleton;
use RuntimeException;
use Throwable;

class RouteSkeletonRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * @return array{translated: int, remaining: int, total: int, already: int, done: bool, warning?: string}
     */
    public function translateBatch(RouteSkeleton $skeleton, int $limit = 20): array
    {
        $skeleton->loadMissing('days');
        $units = $this->units($skeleton);
        if ($units === []) {
            throw new RuntimeException('Нечего переводить');
        }

        $done = is_array($skeleton->ru_units) ? $skeleton->ru_units : [];
        $done = array_intersect_key($done, $units);
        $already = count($done);
        $pending = array_slice(array_diff_key($units, $done), 0, max(1, $limit), true);

        $translated = 0;
        $lastError = null;
        if ($pending !== []) {
            try {
                $map = $this->gemini->translateMap($pending);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $map = [];
                foreach ($pending as $key => $text) {
                    try {
                        $map += $this->gemini->translateMap([$key => $text]);
                    } catch (Throwable $e2) {
                        $lastError = $e2->getMessage();
                    }
                }
            }
            foreach ($pending as $key => $_) {
                $ru = trim((string) ($map[$key] ?? ''));
                if ($ru !== '') {
                    $done[$key] = $ru;
                    $translated++;
                }
            }
        }

        $this->applyRu($skeleton, $units, $done);
        $skeleton->ru_units = $done;
        $skeleton->save();

        $total = count($units);
        $remaining = max(0, $total - count($done));
        $out = [
            'translated' => $translated,
            'remaining' => $remaining,
            'total' => $total,
            'already' => $already,
            'done' => $remaining === 0,
        ];
        if ($translated === 0 && $remaining > 0) {
            $out['warning'] = $lastError ?: 'Gemini не вернул перевод — нажмите ещё раз.';
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function units(RouteSkeleton $skeleton): array
    {
        $units = [];
        $title = trim((string) ($skeleton->title ?? ''));
        if ($title !== '') {
            $units['title'] = $title;
        }
        $arr = trim((string) ($skeleton->arrival_hub ?? ''));
        if ($arr !== '') {
            $units['arrival'] = $arr;
        }
        $dep = trim((string) ($skeleton->departure_hub ?? ''));
        if ($dep !== '') {
            $units['departure'] = $dep;
        }
        foreach ($skeleton->days as $day) {
            $i = (int) $day->day_index;
            $t = trim((string) ($day->title ?? ''));
            if ($t !== '') {
                $units['d'.$i.'_title'] = $t;
            }
            $s = trim((string) ($day->start_point ?? ''));
            if ($s !== '') {
                $units['d'.$i.'_start'] = $s;
            }
            $e = trim((string) ($day->end_point ?? ''));
            if ($e !== '') {
                $units['d'.$i.'_end'] = $e;
            }
            foreach (is_array($day->places) ? $day->places : [] as $pi => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $name = trim((string) ($p['name'] ?? ''));
                if ($name !== '') {
                    $units['d'.$i.'_p'.$pi] = $name;
                }
            }
        }

        return $units;
    }

    /**
     * @param  array<string, string>  $units
     * @param  array<string, string>  $done
     */
    private function applyRu(RouteSkeleton $skeleton, array $units, array $done): void
    {
        if (isset($done['title'])) {
            $skeleton->title_ru = mb_substr($done['title'], 0, 255);
        }
        if (isset($done['arrival'])) {
            $skeleton->arrival_hub_ru = mb_substr($done['arrival'], 0, 120);
        }
        if (isset($done['departure'])) {
            $skeleton->departure_hub_ru = mb_substr($done['departure'], 0, 120);
        }

        foreach ($skeleton->days as $day) {
            $i = (int) $day->day_index;
            $changed = false;
            if (isset($done['d'.$i.'_title'])) {
                $day->title_ru = mb_substr($done['d'.$i.'_title'], 0, 255);
                $changed = true;
            }
            if (isset($done['d'.$i.'_start'])) {
                $day->start_point_ru = mb_substr($done['d'.$i.'_start'], 0, 255);
                $changed = true;
            }
            if (isset($done['d'.$i.'_end'])) {
                $day->end_point_ru = mb_substr($done['d'.$i.'_end'], 0, 255);
                $changed = true;
            }
            $placesRu = [];
            $places = is_array($day->places) ? $day->places : [];
            foreach ($places as $pi => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $key = 'd'.$i.'_p'.$pi;
                $nameRu = isset($done[$key]) ? trim($done[$key]) : '';
                $placesRu[] = [
                    'id' => $p['id'] ?? null,
                    'name' => $nameRu !== '' ? $nameRu : (string) ($p['name'] ?? ''),
                ];
            }
            // only store places_ru when all place units for this day are done (or none)
            $need = 0;
            $have = 0;
            foreach (array_keys($units) as $uk) {
                if (! str_starts_with($uk, 'd'.$i.'_p')) {
                    continue;
                }
                $need++;
                if (isset($done[$uk])) {
                    $have++;
                }
            }
            if ($need > 0 && $have === $need) {
                $day->places_ru = $placesRu;
                $changed = true;
            } elseif ($need === 0) {
                $day->places_ru = [];
            }
            if ($changed) {
                $day->save();
            }
        }
    }
}
