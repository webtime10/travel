<?php

namespace App\Services;

use App\Models\FtShortRoute;
use App\Models\NewShortRoute;

/**
 * New Short (сырой HE Word outline) → чистый формат для Gemini / FineTunes.
 * Основной язык — иврит. RU не собираем здесь: отдельная кнопка перевода.
 */
class FtShortRouteNormalizer
{
    private const HE_WEEKDAY = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';

    private const RU_WEEKDAY = 'воскресенье|понедельник|вторник|среда|четверг|пятница|суббота';

    /**
     * @return array{created:bool,updated:bool,ft:FtShortRoute}
     */
    public function syncFromNewShort(NewShortRoute $nsr): array
    {
        $existing = FtShortRoute::query()
            ->where('new_short_route_id', $nsr->id)
            ->first();

        $payload = $this->buildPayload($nsr, $existing);

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return ['created' => false, 'updated' => true, 'ft' => $existing->fresh()];
        }

        $ft = FtShortRoute::query()->create($payload);

        return ['created' => true, 'updated' => false, 'ft' => $ft];
    }

    /**
     * @return array{
     *   new_short_route_id:int,
     *   doc_code:?string,
     *   title:?string,
     *   title_ru:?string,
     *   days_count:int,
     *   route_text:string,
     *   route_text_ru:?string,
     *   days:list<array{day_index:int,places:string,places_ru:?string,options:list<string>,options_ru:list<string>}>,
     *   meta:array,
     *   ru_units:?array
     * }
     */
    public function buildPayload(NewShortRoute $nsr, ?FtShortRoute $existing = null): array
    {
        // Только оригинал HE из Word (route_text), не RU.
        $heDays = $this->extractCleanDays((string) ($nsr->route_text ?? ''));
        $days = $this->attachPreservedRu($heDays, $existing);

        $routeHe = $this->formatRouteText($days, false);
        $routeRu = $this->formatRouteText($days, true);
        // RU только если уже был перевод (кнопка) — иначе null.
        $hasRu = false;
        foreach ($days as $d) {
            if (trim((string) ($d['places_ru'] ?? '')) !== '') {
                $hasRu = true;
                break;
            }
        }
        if (! $hasRu && $existing && trim((string) ($existing->route_text_ru ?? '')) !== '') {
            // Структура дней сменилась — старый RU-текст не подходит.
            $routeRu = '';
        }

        return [
            'new_short_route_id' => (int) $nsr->id,
            'doc_code' => $nsr->doc_code !== null ? $this->utf8((string) $nsr->doc_code) : null,
            'title' => $nsr->title !== null ? $this->utf8((string) $nsr->title) : null,
            // title_ru сохраняем, если уже переводили кнопкой
            'title_ru' => $existing && trim((string) ($existing->title_ru ?? '')) !== ''
                ? $this->utf8((string) $existing->title_ru)
                : null,
            'days_count' => count($days),
            'route_text' => $this->utf8($routeHe),
            'route_text_ru' => ($hasRu && $routeRu !== '') ? $this->utf8($routeRu) : null,
            'days' => $days,
            'meta' => [
                'source' => 'new_short_route_he',
                'built_at' => now()->toIso8601String(),
                'he_days' => count($heDays),
            ],
            'ru_units' => $existing?->ru_units,
        ];
    }

    /**
     * @param  list<array{day_index:int,places:string,options:list<string>}>  $heDays
     * @return list<array{day_index:int,places:string,places_ru:?string,options:list<string>,options_ru:list<string>}>
     */
    private function attachPreservedRu(array $heDays, ?FtShortRoute $existing): array
    {
        $oldByIndex = [];
        if ($existing && is_array($existing->days)) {
            foreach ($existing->days as $od) {
                if (! is_array($od)) {
                    continue;
                }
                $oldByIndex[(int) ($od['day_index'] ?? 0)] = $od;
            }
        }

        $out = [];
        foreach ($heDays as $i => $h) {
            $idx = (int) ($h['day_index'] ?? ($i + 1));
            $old = $oldByIndex[$idx] ?? null;
            $out[] = [
                'day_index' => $idx,
                'places' => trim((string) ($h['places'] ?? '')),
                'places_ru' => $old ? (trim((string) ($old['places_ru'] ?? '')) ?: null) : null,
                'options' => array_values($h['options'] ?? []),
                'options_ru' => $old ? array_values($old['options_ru'] ?? []) : [],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{day_index:int,places:string,options:list<string>}>
     */
    public function extractCleanDays(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $raw) ?: [];
        $days = [];
        $firstKey = null;
        $pendingDateOnly = null;

        foreach ($lines as $rawLine) {
            // Как в Word-импортере: снимаем никуд, иначе יוֹם שֵׁנִי ≠ יום שני и дни пропадают.
            $line = $this->stripNiqqud(trim($rawLine));
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d{1,2}[.\/]\d{1,2}$/u', $line)) {
                $pendingDateOnly = $line;
                continue;
            }

            if ($pendingDateOnly !== null) {
                $line = $pendingDateOnly.' '.$line;
                $pendingDateOnly = null;
            }

            $parsed = $this->parseDayHeader($line);
            if ($parsed !== null) {
                $key = $parsed['dedupe'];
                if ($firstKey === null) {
                    $firstKey = $key;
                } elseif ($key === $firstKey && $days !== []) {
                    break;
                }

                $days[] = [
                    'day_index' => count($days) + 1,
                    'places' => $parsed['places'],
                    'options' => [],
                ];
                continue;
            }

            if ($days === []) {
                continue;
            }

            if ($this->isOptionLine($line)) {
                $opt = $this->cleanOptionLine($line);
                if ($opt !== '') {
                    $days[count($days) - 1]['options'][] = $opt;
                }
                continue;
            }

            if ($this->looksLikeBodyLeak($line)) {
                break;
            }
        }

        return array_values(array_filter($days, static fn ($d) => trim((string) ($d['places'] ?? '')) !== ''
            || (($d['options'] ?? []) !== [])));
    }

    /**
     * @param  list<array{day_index:int,places:string,places_ru?:?string,options:list<string>,options_ru?:list<string>}>  $days
     */
    public function formatRouteText(array $days, bool $ru): string
    {
        $lines = [];
        foreach ($days as $d) {
            $places = $ru
                ? trim((string) ($d['places_ru'] ?? ''))
                : trim((string) ($d['places'] ?? ''));
            $opts = $ru
                ? array_values($d['options_ru'] ?? [])
                : array_values($d['options'] ?? []);

            $chunk = $places;
            foreach ($opts as $o) {
                $o = trim((string) $o);
                if ($o === '') {
                    continue;
                }
                $chunk = $chunk === '' ? $o : ($chunk.' / '.$o);
            }
            if ($chunk === '') {
                continue;
            }
            $idx = (int) ($d['day_index'] ?? (count($lines) + 1));
            // Тот же формат: HE «יום N:», RU «День N:»
            $prefix = $ru ? ('День '.$idx.': ') : ('יום '.$idx.': ');
            $lines[] = $prefix.$chunk;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{places:string,dedupe:string}|null
     */
    private function parseDayHeader(string $line): ?array
    {
        $he = self::HE_WEEKDAY;
        $ru = self::RU_WEEKDAY;

        if (preg_match(
            '/^(\d{1,2})[.\/](\d{1,2})\s*(?:\((?:יום\s+(?:'.$he.')|'.$ru.')\))?\s*(.+)$/ui',
            $line,
            $m
        )) {
            $places = $this->cleanPlaces(trim($m[3]));
            if ($places === '' || preg_match('/^(?:יום\s+(?:'.$he.')|'.$ru.')$/ui', $places)) {
                return null;
            }
            if ($this->looksLikeBodyLeak($places) && ! $this->looksLikePlaces($places)) {
                return null;
            }

            return [
                'places' => $places,
                'dedupe' => ((int) $m[1]).'|'.((int) $m[2]),
            ];
        }

        if (preg_match(
            '/^(?:יום\s+(?:'.$he.')|'.$ru.')\s*[-–—]\s*(\d{1,2})\s*[.\/]\s*(\d{1,2})\s*[-–—]\s*(.+)$/ui',
            $line,
            $m
        )) {
            $places = $this->cleanPlaces(trim($m[3]));
            if ($places === '') {
                return null;
            }

            return [
                'places' => $places,
                'dedupe' => ((int) $m[1]).'|'.((int) $m[2]),
            ];
        }

        if (preg_match(
            '/^\((?:יום\s+(?:'.$he.')|'.$ru.')\)\s*(.+)$/ui',
            $line,
            $m
        )) {
            $places = $this->cleanPlaces(trim($m[1]));
            if ($places === '') {
                return null;
            }

            return [
                'places' => $places,
                'dedupe' => 'paren|'.mb_strtolower($places),
            ];
        }

        return null;
    }

    private function isOptionLine(string $line): bool
    {
        return (bool) preg_match('/^(?:אופציה|вариант)\s*[אבabаб]?/ui', $line);
    }

    private function cleanOptionLine(string $line): string
    {
        $t = preg_replace('/^(?:אופציה|вариант)\s*[אבabаб]?\s*[-–—:]?\s*/ui', '', $line) ?? $line;

        return $this->cleanPlaces(trim($t));
    }

    private function cleanPlaces(string $s): string
    {
        $s = $this->utf8($this->stripNiqqud($s));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\bTOC\b.*$/ui', '', $s) ?? $s;
        $s = preg_replace('/\\\\\w+\b/u', '', $s) ?? $s;
        $s = preg_replace('/\d{9,}/u', '', $s) ?? $s;
        $s = preg_replace('/^[\s,;:\-–—]+|[\s,;:\-–—]+$/u', '', $s) ?? $s;

        return $this->utf8($s);
    }

    /** Hebrew niqqud / cantillation — иначе дни с יוֹם не матчятся. */
    private function stripNiqqud(string $s): string
    {
        return preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s) ?? $s;
    }

    private function utf8(string $s): string
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

    private function looksLikePlaces(string $s): bool
    {
        return mb_strlen($s) <= 180 && ! preg_match('/https?:\/\//ui', $s);
    }

    private function looksLikeBodyLeak(string $line): bool
    {
        if (preg_match('/https?:\/\//ui', $line)) {
            return true;
        }
        if (preg_match('/^(?:כתובת|адрес|קישור|ссылка|google|waze|שעות|часы|זמן נסיעה|время в пути)/ui', $line)) {
            return true;
        }
        if (preg_match('/(?:אחרי שתנחתו|после прилета|זמני נסיעות|время в пути и расстояние)/ui', $line)) {
            return true;
        }
        if (mb_strlen($line) > 220) {
            return true;
        }

        return false;
    }
}
