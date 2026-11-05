<?php

namespace App\Services;

use App\Models\NewShortRoute;
use RuntimeException;
use Throwable;

class NewShortRouteRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * @return array{translated: int, remaining: int, total: int, already: int, done: bool, warning?: string}
     */
    public function translateBatch(NewShortRoute $route, int $limit = 20): array
    {
        $route->loadMissing('days');
        $units = $this->units($route);
        if ($units === []) {
            throw new RuntimeException('Нечего переводить');
        }

        $done = is_array($route->ru_units) ? $route->ru_units : [];
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

        $this->applyRu($route, $units, $done);
        $route->ru_units = $done;
        $route->save();

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
    public function units(NewShortRoute $route): array
    {
        $units = [];
        foreach ([
            'title' => $route->title,
            'arrival' => $route->arrival_hub,
            'departure' => $route->departure_hub,
            'route_text' => $route->route_text,
        ] as $k => $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            // route_text может быть длинным — режем на куски для Gemini
            if ($k === 'route_text' && mb_strlen($v) > 1200) {
                $chunks = $this->chunkText($v, 1000);
                foreach ($chunks as $ci => $chunk) {
                    $units['route_text_'.$ci] = $chunk;
                }
            } else {
                $units[$k] = $v;
            }
        }
        foreach ($route->days as $day) {
            $i = (int) $day->day_index;
            foreach ([
                'd'.$i.'_title' => $day->title,
                'd'.$i.'_end' => $day->end_point,
                'd'.$i.'_overnight' => $day->overnight_text,
            ] as $k => $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $units[$k] = $v;
                }
            }
        }

        return $units;
    }

    /**
     * @param  array<string, string>  $units
     * @param  array<string, string>  $done
     */
    private function applyRu(NewShortRoute $route, array $units, array $done): void
    {
        if (isset($done['title'])) {
            $route->title_ru = $done['title'];
        }
        if (isset($done['arrival'])) {
            $route->arrival_hub_ru = $done['arrival'];
        }
        if (isset($done['departure'])) {
            $route->departure_hub_ru = $done['departure'];
        }
        if (isset($done['route_text'])) {
            $route->route_text_ru = $done['route_text'];
        } else {
            $parts = [];
            foreach ($done as $k => $ru) {
                if (str_starts_with($k, 'route_text_')) {
                    $parts[(int) substr($k, strlen('route_text_'))] = $ru;
                }
            }
            if ($parts !== []) {
                ksort($parts);
                $route->route_text_ru = implode("\n", $parts);
            }
        }
        foreach ($route->days as $day) {
            $i = (int) $day->day_index;
            $changed = false;
            if (isset($done['d'.$i.'_title'])) {
                $day->title_ru = $done['d'.$i.'_title'];
                $changed = true;
            }
            if (isset($done['d'.$i.'_end'])) {
                $day->end_point_ru = $done['d'.$i.'_end'];
                $changed = true;
            }
            if (isset($done['d'.$i.'_overnight'])) {
                $day->overnight_text_ru = $done['d'.$i.'_overnight'];
                $changed = true;
            }
            if ($changed) {
                $day->save();
            }
        }
        unset($units);
    }

    /**
     * @return list<string>
     */
    private function chunkText(string $text, int $size = 1000): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $chunks = [];
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i += $size) {
            $chunks[] = mb_substr($text, $i, $size);
        }

        return $chunks;
    }
}
