<?php

namespace App\Services;

use App\Models\NewShortRoute;
use App\Models\NewShortRouteDay;
use ZipArchive;

/**
 * Scan travel.loc/doc/*.docx → new_short_routes (+ days / overnight heuristic).
 */
class NewShortRouteDocImporter
{
    public function docDir(): string
    {
        return base_path('doc');
    }

    /**
     * @return array{scanned: int, upserted: int, skipped: int, errors: list<string>}
     */
    public function import(int $limit = 40, bool $onlySince2023 = true, bool $parseBody = true): array
    {
        $dir = $this->docDir();
        $out = ['scanned' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => []];
        if (! is_dir($dir)) {
            $out['errors'][] = 'Нет папки doc: '.$dir;

            return $out;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.docx') ?: [];
        $files = array_values(array_filter($files, function ($path) {
            $base = basename($path);

            // Word lock / temp
            return ! str_starts_with($base, '~$') && ! str_starts_with($base, '.');
        }));
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $cutoff = strtotime('2023-01-01');
        foreach ($files as $path) {
            if ($out['upserted'] >= $limit) {
                break;
            }
            $out['scanned']++;
            $name = basename($path);
            if ($onlySince2023 && filemtime($path) < $cutoff) {
                $out['skipped']++;

                continue;
            }
            try {
                $this->importOne($path, $parseBody);
                $out['upserted']++;
            } catch (\Throwable $e) {
                $out['skipped']++;
                if (count($out['errors']) < 15) {
                    $out['errors'][] = $name.': '.$e->getMessage();
                }
            }
        }

        return $out;
    }

    public function importOne(string $path, bool $parseBody = true): NewShortRoute
    {
        $filename = $this->utf8OrEmpty(basename($path)) ?: basename($path);
        $code = $this->extractDocCode($filename);
        $title = $this->titleFromFilename($filename, $code);
        if ($title === '' || ! preg_match('//u', $title)) {
            $title = $code ? 'Маршрут '.$code : 'doc';
        }

        $days = [];
        $bodyText = null;
        $routeText = null;
        $rawPreview = null;

        if ($parseBody) {
            $text = $this->extractDocxPlainText($path);
            $bodyText = $text;
            $rawPreview = mb_substr($text, 0, 2000);
            $days = $this->parseDaysAndOvernights($text);
            // Маршрут сверху — в том же порядке, как в Word (оглавление), без «День N:».
            $routeText = $this->extractRouteOutlineFromWord($text);
            // Ночёвки/хабы не угадываем — только дни + полный текст для RAG.
        }

        if ($days === []) {
            $days = [[
                'day_index' => 0,
                'title' => $title,
                'overnight_text' => null,
                'end_point' => null,
                'places' => [],
            ]];
        }

        if ($routeText === null || trim((string) $routeText) === '') {
            $routeText = $this->buildRouteText($title, $days);
        }

        // HE из Word; RU не трогаем — иначе «Перечитать Word» сносит готовый перевод.
        $route = NewShortRoute::query()->updateOrCreate(
            [
                'doc_code' => $code,
                'source_filename' => $filename,
            ],
            [
                'title' => $title,
                'days_count' => count($days),
                'arrival_hub' => null,
                'departure_hub' => null,
                'search_blob' => trim($code.' '.$title.' '.$filename.' '.mb_substr((string) $bodyText, 0, 1500)),
                'route_text' => $routeText,
                'body_text' => $bodyText,
                'meta' => [
                    'source' => 'doc_folder',
                    'mtime' => filemtime($path),
                    'preview' => $rawPreview,
                    'body_chars' => $bodyText !== null ? mb_strlen($bodyText) : 0,
                ],
            ]
        );

        // route_text изменился — сбрасываем только RU маршрута/юниты, title_ru/body_ru оставляем если есть.
        if ($route->wasChanged('route_text') || $route->wasRecentlyCreated) {
            $route->route_text_ru = null;
            $route->ru_units = null;
            $route->save();
        }

        $route->arrival_location_id = null;
        $route->departure_location_id = null;
        $route->save();

        $route->days()->delete();
        foreach ($days as $d) {
            $titleDay = isset($d['title']) ? $this->cleanDayTitle((string) $d['title']) : null;
            NewShortRouteDay::query()->create([
                'new_short_route_id' => $route->id,
                'day_index' => (int) ($d['day_index'] ?? 0),
                'title' => $titleDay !== '' ? $titleDay : null,
                'title_ru' => null,
                'end_point' => null,
                'end_point_ru' => null,
                'overnight_text' => null,
                'overnight_text_ru' => null,
                'overnight_note' => null,
                'is_overnight' => false,
                'overnight_location_id' => null,
                'places' => $d['places'] ?? [],
                'places_ru' => null,
            ]);
        }

        return $route->fresh('days');
    }

    /**
     * Оглавление / шапка маршрута из Word — тот же порядок строк, без своих префиксов.
     * Берём блок с начала до повтора первого дня (тело) или до «תודה/הסבר».
     */
    public function extractRouteOutlineFromWord(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        $firstDayNorm = null;
        $seenFirstDay = false;

        foreach ($lines as $raw) {
            $line = trim($this->stripWordJunk($raw));
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Техполя Word + явный мусор оглавления (תוכן / ссылка на карту) — не маршрут.
            if (preg_match('/^(?:TOC\s*[\\\\o]|\\\\[a-z])/i', $line)) {
                continue;
            }
            if (preg_match('/^תוכן\s*$/u', $line)) {
                continue;
            }
            if (preg_match('/^קישור\s+(?:מפה|למפה)\b/u', $line) || preg_match('/מפה\s+בגוגל/u', $line)) {
                continue;
            }
            // Заголовок маршрута уже в title карточки — в route_text не дублируем.
            if ($out === [] && preg_match('/^מסלול\s+ל/u', $line)) {
                continue;
            }
            // Реклама авто / советы — не дни маршрута (для карточки и FT).
            if ($this->isRouteOutlineJunk($line)) {
                continue;
            }
            // Конец оглавления / начало пояснений
            if (preg_match('/^(?:תודה רבה|הסבר על מבנה|איך לקרוא את המסלול)/u', $line)) {
                break;
            }

            $isDay = $this->isItineraryDayHeader($line);
            if ($isDay) {
                $norm = $this->dayDedupeKey($this->cleanDayTitle($line));
                if ($firstDayNorm === null) {
                    $firstDayNorm = $norm;
                    $seenFirstDay = true;
                } elseif ($seenFirstDay && $norm === $firstDayNorm && $out !== []) {
                    // Второй раз тот же первый день = уже тело документа, не TOC
                    break;
                }
            }

            $out[] = $line;
            if (count($out) >= 60) {
                break;
            }
        }

        return implode("\n", $out);
    }

    /** Строки оглавления Word, которые не входят в маршрут (תוכן, реклама авто, советы). */
    public function isRouteOutlineJunk(string $line): bool
    {
        $t = trim($line);
        if ($t === '') {
            return true;
        }
        if (preg_match('/^תוכן\s*$/u', $t)) {
            return true;
        }
        if (preg_match('/^(?:קישור\s+(?:מפה|למפה)|מפה\s+בגוגל)/u', $t)) {
            return true;
        }
        // Реклама аренды авто (шапка/подвал TOC) — не день маршрута.
        if (preg_match('/השכרת\s+רכב/u', $t) || preg_match('/^מבצעים\s+בלעדיים/u', $t)) {
            return true;
        }
        // «עצות לנהיגה» — советы, не пункт маршрута.
        if (preg_match('/^עצות\s+לנהיגה/u', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Fallback: список дней без префикса «День N:» — порядок как в Word.
     *
     * @param  list<array{day_index?:int,title?:?string}>  $days
     */
    public function buildRouteText(string $title, array $days): string
    {
        // title карточки отдельно — сюда только дни (порядок как в Word).
        unset($title);
        $lines = [];
        foreach ($days as $d) {
            $t = trim((string) ($d['title'] ?? ''));
            if ($t !== '') {
                $lines[] = $t;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * First 4-digit group that is not a year 2020–2039.
     */
    public function extractDocCode(string $filename): ?string
    {
        if (! preg_match_all('/(?<!\d)(\d{4})(?!\d)/', $filename, $m)) {
            return null;
        }
        foreach ($m[1] as $num) {
            $n = (int) $num;
            if ($n >= 2020 && $n <= 2039) {
                continue;
            }

            return $num;
        }

        return $m[1][0] ?? null;
    }

    public function titleFromFilename(string $filename, ?string $code): string
    {
        $base = preg_replace('/\.docx$/i', '', $filename) ?? $filename;
        $base = preg_replace('/^Копия\s+/u', '', $base) ?? $base;
        $base = preg_replace('/^Copy of\s+/i', '', $base) ?? $base;
        if ($code) {
            $base = preg_replace('/\b'.preg_quote($code, '/').'\b/', '', $base) ?? $base;
        }
        // Drop year tokens
        $base = preg_replace('/\b20[2-3]\d\b/', '', $base) ?? $base;
        // Drop date-like dd-mm
        $base = preg_replace('/\b\d{1,2}[-.\/]\d{1,2}(?:[-.\/]\d{2,4})?\b/', '', $base) ?? $base;
        $base = preg_replace('/[\s_\-–—]+/u', ' ', $base) ?? $base;
        // Нельзя trim(..., '–—'): PHP режет по байтам и ломает UTF-8 (иврит в конце).
        $base = preg_replace('/^[\s_\-–—]+|[\s_\-–—]+$/u', '', $base) ?? $base;
        $base = $this->utf8OrEmpty($base);

        return $base !== '' ? $base : ($code ? 'Маршрут '.$code : $this->utf8OrEmpty($filename));
    }

    /** Оставляет только валидный UTF-8 (для MySQL / Windows-имён). */
    private function utf8OrEmpty(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (preg_match('//u', $s)) {
            return $s;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return is_string($fixed) ? $fixed : '';
    }

    public function extractDocxPlainText(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Не открыть docx');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            throw new \RuntimeException('Нет word/document.xml');
        }
        $xml = str_replace('</w:p>', "\n", $xml);
        $xml = str_replace('</w:tr>', "\n", $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->stripWordJunk($text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<array{day_index:int,title:?string,overnight_text:?string,end_point:?string,places:array}>
     */
    public function parseDaysAndOvernights(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $found = [];

        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '') {
                continue;
            }
            if (! $this->isItineraryDayHeader($raw)) {
                continue;
            }
            $title = $this->cleanDayTitle($raw);
            if ($title === '') {
                continue;
            }
            $key = $this->dayDedupeKey($title);
            // TOC often repeats the same headers before the body — keep the LAST (real section).
            $found[$key] = [
                'title' => $title,
                'overnight_text' => null,
                'end_point' => null,
                'places' => [],
            ];
        }

        $days = [];
        foreach (array_values($found) as $i => $d) {
            $d['day_index'] = $i;
            $days[] = $d;
        }

        return $days;
    }

    public function stripWordJunk(string $text): string
    {
        $text = preg_replace('/PAGEREF\s+_Toc\d+\s+\\\\h\s*\d*/ui', ' ', $text) ?? $text;
        $text = preg_replace('/HYPERLINK\s+"[^"]*"/ui', ' ', $text) ?? $text;
        $text = preg_replace('/_Toc\d+/u', ' ', $text) ?? $text;

        return $text;
    }

    /**
     * Real itinerary day titles (יום ראשון - 1/11 - …), not TOC junk / opening hours.
     */
    public function isItineraryDayHeader(string $line): bool
    {
        $line = $this->cleanDayTitle($line);
        if ($line === '') {
            return false;
        }

        // Hebrew day-of-week after optional niqqud already stripped.
        $heDay = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        if (! preg_match('/^(?:יום\s+(?:'.$heDay.')|Day\s+\d+|День\s+\d+)\b/ui', $line)) {
            return false;
        }

        // Opening hours / closed snippets from attraction blocks.
        if (preg_match('/\b(?:סגור|שעות\s*הפעילות|closed)\b/ui', $line)) {
            return false;
        }
        // "יום שני- 11:00–15:00" without trip date.
        if (preg_match('/\d{1,2}:\d{2}/', $line) && ! preg_match('/\d{1,2}\/\d{1,2}/', $line)) {
            return false;
        }
        // Compound closed: "יום רביעי-חמישי סגור" already caught; also reject weekday-weekday only.
        if (preg_match('/^יום\s+(?:'.$heDay.')\s*[-–—]\s*(?:'.$heDay.')\b/ui', $line)
            && ! preg_match('/\d{1,2}\/\d{1,2}/', $line)) {
            return false;
        }

        // Dated trip day: יום … - 4/11 - …
        if (preg_match('/\d{1,2}\/\d{1,2}/', $line)) {
            return true;
        }

        // Descriptive header with separator and enough content.
        if (mb_strlen($line) >= 28 && preg_match('/\s[-–—]\s/u', $line)) {
            return true;
        }

        return false;
    }

    public function cleanDayTitle(string $line): string
    {
        $line = $this->stripWordJunk($line);
        // Strip Hebrew niqqud so יוֹם רִאשׁוֹן ≡ יום ראשון
        $line = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $line) ?? $line;
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

        return trim($line);
    }

    private function dayDedupeKey(string $title): string
    {
        $t = mb_strtolower($title);
        if (preg_match('/(יום\s+(?:ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת)).{0,20}?(\d{1,2}\/\d{1,2})/ui', $title, $m)) {
            return mb_strtolower(trim($m[1].'|'.$m[2]));
        }

        return $t;
    }

    private function extractOvernightFromLine(string $line): ?string
    {
        if ($line === '') {
            return null;
        }
        // Hebrew לינה
        if (preg_match('/לינה\s*[:\-–—]?\s*(.+)$/ui', $line, $m)) {
            return $this->cleanOvernight(trim($m[1]));
        }
        if (preg_match('/\b(?:overnight|hotel|ночёвка|ночевка)\s*[:\-–—]?\s*(.+)$/ui', $line, $m)) {
            return $this->cleanOvernight(trim($m[1]));
        }
        // "לינה בשדה" / airport field
        if (preg_match('/לינה\s+(.+)$/ui', $line, $m)) {
            return $this->cleanOvernight(trim($m[1]));
        }

        return null;
    }

    private function cleanOvernight(string $s): ?string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B.,;:");
        if ($s === '' || mb_strlen($s) < 2) {
            return null;
        }
        if (mb_strlen($s) > 240) {
            $s = mb_substr($s, 0, 240);
        }

        return $s;
    }
}
