<?php

namespace App\Services;

use App\Models\NewShortRoute;
use App\Models\NewShortRouteDay;

/**
 * Ask Gemini (by meaning) which days are overnights — Hebrew labels, no location ids.
 */
class NewShortRouteOvernightGemini
{
    public function __construct(
        private GeminiJsonClient $gemini,
        private NewShortRouteDocImporter $importer,
        private GeminiKeyPool $keys,
    ) {}

    /**
     * @return array{ok: bool, days_marked: int, message: string}
     */
    public function suggestForRoute(NewShortRoute $route): array
    {
        $this->keys->resetToFirst();

        $file = (string) ($route->source_filename ?? '');
        $path = $this->importer->docDir().DIRECTORY_SEPARATOR.$file;
        if ($file === '' || ! is_file($path)) {
            return ['ok' => false, 'days_marked' => 0, 'message' => 'DOCX не найден: '.$file];
        }

        $route->loadMissing('days');
        $dayList = [];
        foreach ($route->days as $d) {
            $dayList[] = [
                'day_index' => (int) $d->day_index,
                'title_he' => (string) ($d->title ?? ''),
            ];
        }
        $daysJson = json_encode($dayList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $n = count($dayList);
        $expectHint = $n <= 1 ? '0 или 1' : 'обычно '.max(0, $n - 1).'–'.$n.' (почти каждый день, кроме возможно дня вылета)';

        $text = $this->importer->extractDocxPlainText($path);
        $text = $this->stripWordJunk($text);
        // Cap for free-tier / cost
        $text = mb_substr($text, 0, 28000);

        $material = "DAYS_FROM_DB (обязательные day_index):\n".$daysJson."\n\n--- SOURCE DOCX ---\n".$text;

        $instruction = <<<PROMPT
Ты разбираешь туристический маршрут на иврите (Word) и список дней DAYS_FROM_DB.

Главная задача: для КАЖДОГО дня, где туристы СПЯТ ночью, поставить ночёвку (לינה / отель / база / город ночёвки).

Важно — НЕ будь слишком скупым:
- Многодневный маршрут = ночёвка почти КАЖДУЮ ночь до вылета.
- Дней в маршруте: {$n}. Ожидай примерно {$expectHint} ночёвок.
- Если база/отель один на несколько дней — ПОВТОРИ одну и ту же overnight_text_he для КАЖДОГО такого day_index отдельно.
- День прилёта (נחיתה) почти всегда с ночёвкой.
- День вылета (טיסה) часто БЕЗ ночёвки после вылета — но если ночуют накануне вылета, отметь предыдущий день.
- Если в тексте база названа один раз в начале («לינה ב…») и маршрут катается вокруг — ставь эту базу на все ночи, пока явно не сменят район.

Игнорируй:
- PAGEREF, TOC, _Toc, оглавление
- рекламу авто, общие советы, часы работы аттракционов

Верни СТРОГО JSON:
{
  "arrival_hub_he": "иврит: аэропорт/город прилёта или null",
  "departure_hub_he": "иврит: аэропорт/город вылета или null",
  "overnights": [
    {
      "day_hint": "как в title_he",
      "day_index": 0,
      "overnight_text_he": "коротко иврит: город/база/отель",
      "note_he": "короткая заметка иврит или null"
    }
  ]
}

Правила:
- day_index ТОЛЬКО из DAYS_FROM_DB (0..N-1).
- overnight_text_he только иврит, без PAGEREF.
- Лучше отметить лишнюю спорную ночёвку, чем пропустить явные ночи маршрута.
- Не оставляй массив overnights из одного элемента, если дней больше 3 и это не однодневка.
PROMPT;

        $raw = $this->gemini->ask($instruction, $material, 'low');
        $marked = $this->applyToRoute($route, $raw);

        return [
            'ok' => true,
            'days_marked' => $marked,
            'message' => 'Gemini отметил ночёвок: '.$marked,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function applyToRoute(NewShortRoute $route, array $raw): int
    {
        $route->loadMissing('days');
        if ($route->days->isEmpty()) {
            return 0;
        }

        $arr = trim((string) ($raw['arrival_hub_he'] ?? ''));
        $dep = trim((string) ($raw['departure_hub_he'] ?? ''));
        if ($arr !== '' && ! $this->looksLikeJunk($arr)) {
            $route->arrival_hub = $arr;
        }
        if ($dep !== '' && ! $this->looksLikeJunk($dep)) {
            $route->departure_hub = $dep;
        }
        $route->arrival_location_id = null;
        $route->departure_location_id = null;
        $route->save();

        // Reset overnight flags
        foreach ($route->days as $day) {
            $day->is_overnight = false;
            $day->overnight_text = null;
            $day->overnight_note = null;
            $day->overnight_location_id = null;
            $day->save();
        }

        $byIndex = $route->days->keyBy(fn (NewShortRouteDay $d) => (int) $d->day_index);

        $marked = 0;
        $list = is_array($raw['overnights'] ?? null) ? $raw['overnights'] : [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $idx = (int) ($row['day_index'] ?? -1);
            /** @var NewShortRouteDay|null $day */
            $day = $byIndex->get($idx);
            if (! $day) {
                continue;
            }
            $he = trim((string) ($row['overnight_text_he'] ?? ''));
            if ($he === '' || $this->looksLikeJunk($he)) {
                continue;
            }
            $note = trim((string) ($row['note_he'] ?? ''));
            if ($this->looksLikeJunk($note)) {
                $note = '';
            }
            $day->is_overnight = true;
            $day->overnight_text = mb_substr($he, 0, 500);
            $day->overnight_note = $note !== '' ? mb_substr($note, 0, 1000) : null;
            $day->end_point = $day->overnight_text;
            $day->overnight_location_id = null;
            $day->save();
            $marked++;
        }

        return $marked;
    }

    public function stripWordJunk(string $text): string
    {
        $text = preg_replace('/PAGEREF\s+_Toc\d+\s+\\\\h\s*\d*/ui', ' ', $text) ?? $text;
        $text = preg_replace('/HYPERLINK\s+"[^"]*"/ui', ' ', $text) ?? $text;
        $text = preg_replace('/_Toc\d+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function looksLikeJunk(string $s): bool
    {
        return (bool) preg_match('/PAGEREF|_Toc|HYPERLINK/ui', $s);
    }
}
