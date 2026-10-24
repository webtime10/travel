<?php

namespace App\Services;

use App\Models\GoldenDay;
use RuntimeException;
use Throwable;

class GoldenDayRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * @return array{translated: int, remaining: int, total: int, already: int, done: bool, warning?: string}
     */
    public function translateBatch(GoldenDay $day, int $limit = 20): array
    {
        $day->loadMissing('stops');
        $units = $this->units($day);
        if ($units === []) {
            throw new RuntimeException('Нечего переводить');
        }

        $done = is_array($day->ru_units) ? $day->ru_units : [];
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

        $this->applyRu($day, $done);
        $day->ru_units = $done;
        $day->save();

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
     * Translate next unfinished golden days (one day fully per call by default).
     *
     * @return array{
     *   processed: int,
     *   remaining_days: int,
     *   day_id: int|null,
     *   done: bool,
     *   day?: array<string, mixed>,
     *   warning?: string
     * }
     */
    public function translateNextPendingDays(int $daysLimit = 1, int $unitsLimit = 40): array
    {
        $daysLimit = max(1, min(5, $daysLimit));
        $processed = 0;
        $lastDayId = null;
        $lastDayResult = null;
        $warning = null;

        for ($i = 0; $i < $daysLimit; $i++) {
            $day = GoldenDay::query()
                ->with('stops')
                ->where(function ($q) {
                    $q->whereNull('title_ru')->orWhere('title_ru', '');
                })
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->orderBy('id')
                ->first();

            if (! $day) {
                break;
            }

            // Finish this day completely (loop units)
            $safety = 20;
            $dayResult = null;
            while ($safety-- > 0) {
                $dayResult = $this->translateBatch($day->fresh(['stops']), $unitsLimit);
                if ($dayResult['done']) {
                    break;
                }
                if (($dayResult['translated'] ?? 0) === 0) {
                    $warning = $dayResult['warning'] ?? $warning;
                    break;
                }
            }

            $processed++;
            $lastDayId = (int) $day->id;
            $lastDayResult = $dayResult;
            if (! ($dayResult['done'] ?? false)) {
                break;
            }
        }

        $remainingDays = $this->countPendingDays();

        $out = [
            'processed' => $processed,
            'remaining_days' => $remainingDays,
            'day_id' => $lastDayId,
            'done' => $remainingDays === 0,
        ];
        if ($lastDayResult !== null) {
            $out['day'] = $lastDayResult;
        }
        if ($warning) {
            $out['warning'] = $warning;
        }

        return $out;
    }

    public function countPendingDays(): int
    {
        // Approximate: no title_ru yet (main signal). Full scan of ru_units is expensive.
        return (int) GoldenDay::query()
            ->where(function ($q) {
                $q->whereNull('title_ru')->orWhere('title_ru', '');
            })
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->count();
    }

    /**
     * @return array<string, string>
     */
    public function units(GoldenDay $day): array
    {
        $units = [];
        $title = trim((string) ($day->title ?? ''));
        if ($title !== '') {
            $units['title'] = $title;
        }
        $start = trim((string) ($day->start_point ?? ''));
        if ($start !== '') {
            $units['start'] = $start;
        }
        $end = trim((string) ($day->end_point ?? ''));
        if ($end !== '') {
            $units['end'] = $end;
        }
        foreach ($day->stops as $stop) {
            $name = trim((string) ($stop->name ?? ''));
            if ($name !== '') {
                $units['s'.$stop->id] = $name;
            }
        }

        return $units;
    }

    /**
     * @param  array<string, string>  $done
     */
    private function applyRu(GoldenDay $day, array $done): void
    {
        if (isset($done['title'])) {
            $day->title_ru = mb_substr($done['title'], 0, 255);
        }
        if (isset($done['start'])) {
            $day->start_point_ru = mb_substr($done['start'], 0, 255);
        }
        if (isset($done['end'])) {
            $day->end_point_ru = mb_substr($done['end'], 0, 255);
        }

        foreach ($day->stops as $stop) {
            $key = 's'.$stop->id;
            if (! isset($done[$key])) {
                continue;
            }
            $stop->name_ru = mb_substr(trim($done[$key]), 0, 255);
            $stop->save();
        }
    }
}
