<?php

namespace App\Services\Itinerary;

use App\Services\GeminiKeyPool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Stage 1.5: from raw sources → ALL route days (junk stripped, no questionnaire filter).
 */
class CuratedDayPoolBuilder
{
    public function __construct(
        private GeminiKeyPool $keys,
        private QuestionnaireBrief $briefParser,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array{
     *   days: list<array<string, mixed>>,
     *   preferred_regions: list<string>,
     *   constraints: array<string, mixed>,
     *   extracted: int,
     *   kept: int,
     *   dropped: list<array<string, mixed>>,
     *   brief_note: string,
     *   ms: int
     * }
     */
    public function build(string $question, array $sources): array
    {
        $t0 = microtime(true);
        $constraints = $this->briefParser->parse($question);
        $preferred = $this->detectPreferredRegions($question);
        $all = [];
        $fromRouteLines = 0;
        $fromFullText = 0;

        foreach ($sources as $i => $src) {
            if (! is_array($src)) {
                continue;
            }
            $meta = [
                'product_id' => (int) ($src['product_id'] ?? 0) ?: null,
                'model' => isset($src['model']) ? (string) $src['model'] : null,
                'source_index' => (int) $i,
            ];

            $routeLines = [];
            if (is_array($src['route_lines'] ?? null)) {
                foreach ($src['route_lines'] as $line) {
                    if (! is_string($line)) {
                        continue;
                    }
                    $line = trim($line);
                    if ($line !== '') {
                        $routeLines[] = $line;
                    }
                }
            }

            // Prefer already-matched itinerary lines — no Gemini per source.
            if ($routeLines !== []) {
                foreach ($this->daysFromRouteLines($routeLines, $meta) as $day) {
                    $all[] = $day;
                    $fromRouteLines++;
                }

                continue;
            }

            $text = trim((string) ($src['preview_text_ru'] ?? ''));
            if ($text === '') {
                $text = trim((string) ($src['preview_text'] ?? ''));
            }
            if ($text === '' || $text === '(нет текстовых блоков в meta)') {
                continue;
            }
            $text = $this->preferProgramSlice($text, 28000);

            $extracted = [];
            try {
                $extracted = $this->extractDaysFromText($text, $meta);
            } catch (\Throwable $e) {
                Log::warning('Curate Gemini extract failed, using heuristic', [
                    'product_id' => $meta['product_id'],
                    'message' => $e->getMessage(),
                ]);
            }

            $heuristic = $this->extractDaysHeuristic($text, $meta);
            $extracted = $this->mergeDayLists($extracted, $heuristic);

            foreach ($extracted as $day) {
                $all[] = $day;
                $fromFullText++;
            }
        }

        // Sense-dedupe: same places ≠ keep two cards just because dates differ
        $days = $this->dedupeBySense($all);
        foreach ($days as &$day) {
            $day['fit'] = true;
        }
        unset($day);

        $note = 'Строки маршрута → карточки дней. Дубли по смыслу мест убраны (даты не важны). '
            .count($days).' уник. дн. из '.count($sources).' источник(ов)'
            .' (сырых '.$fromRouteLines.' строк + '.$fromFullText.' из полного текста).';

        return [
            'days' => $days,
            'dropped' => [],
            'brief_note' => $note,
            'preferred_regions' => $preferred,
            'constraints' => $constraints,
            'extracted' => count($all),
            'kept' => count($days),
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * One itinerary route line → one day card.
     *
     * @param  list<string>  $lines
     * @param  array{product_id: int|null, model: string|null, source_index: int}  $meta
     * @return list<array<string, mixed>>
     */
    private function daysFromRouteLines(array $lines, array $meta): array
    {
        $out = [];
        foreach (array_values($lines) as $h => $line) {
            $line = trim((string) $line);
            if ($line === '' || mb_strlen($line) < 8 || $this->isNoiseChunk($line)) {
                continue;
            }
            $places = $this->placesFromHeader($line);
            $region = $this->guessRegionFromText($line.' '.implode(' ', $places));
            $kind = 'program';
            if (preg_match('/аэропорт|airport|נחיתה|טיסה|מלפנסה|милан.*ночев|לינה.*שדה|ночевк|переезд|трансфер|עוברים|מעבר/ui', $line) === 1
                && preg_match('/озер|אגם|водопад|מפל|ортизеи|тенно|мольвено|גארדה|דולומיט/ui', $line) !== 1) {
                $kind = preg_match('/вылет|חזרה|departure|טיסת חזרה/ui', $line) === 1 ? 'departure' : 'arrival';
            } elseif (preg_match('/^(?:переезжа|переезд|трансфер|עוברים|מעבר)\b/iu', $line) === 1) {
                $kind = 'transfer';
            }

            $summary = $line;
            if (mb_strlen($summary) > 140) {
                $summary = mb_substr($summary, 0, 140).'…';
            }

            $out[] = [
                'id' => 's'.$meta['source_index'].'_rl'.($h + 1),
                'product_id' => $meta['product_id'],
                'model' => $meta['model'],
                'places' => $places !== [] ? $places : [mb_substr($this->stripTemporalNoise($line), 0, 80)],
                'region' => $region,
                'summary' => $summary,
                'kind' => $kind,
                'body' => $line,
                'fit' => true,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $primary
     * @param  list<array<string, mixed>>  $extra
     * @return list<array<string, mixed>>
     */
    private function mergeDayLists(array $primary, array $extra): array
    {
        $out = $primary;
        $seen = [];
        foreach ($primary as $day) {
            $seen[$this->fingerprint($day)] = true;
        }
        foreach ($extra as $day) {
            $fp = $this->fingerprint($day);
            if (isset($seen[$fp])) {
                // Prefer longer body if duplicate
                foreach ($out as &$existing) {
                    if ($this->fingerprint($existing) !== $fp) {
                        continue;
                    }
                    $a = (string) ($existing['body'] ?? '');
                    $b = (string) ($day['body'] ?? '');
                    if (mb_strlen($b) > mb_strlen($a)) {
                        $existing['body'] = $b;
                    }
                    break;
                }
                unset($existing);

                continue;
            }
            $seen[$fp] = true;
            $out[] = $day;
        }

        return $out;
    }

    /**
     * Parse editable textarea back into day cards for assemble.
     *
     * @return list<array<string, mixed>>
     */
    public function parseCuratedText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $blocks = preg_split('/\n\s*\n/u', $text) ?: [];
        $out = [];
        $n = 0;
        foreach ($blocks as $block) {
            $block = trim((string) $block);
            if ($block === '') {
                continue;
            }
            $lines = preg_split('/\R/u', $block) ?: [];
            $header = trim((string) ($lines[0] ?? ''));
            if ($header === '') {
                continue;
            }
            $region = 'other';
            $summary = $header;
            if (preg_match('/^(?:\d+\.\s*)?\[([a-z]+)\]\s*(.+)$/iu', $header, $m)) {
                $region = strtolower($m[1]);
                $summary = trim($m[2]);
            }
            if (! in_array($region, ['garda', 'dolomiti', 'como', 'tuscany', 'rome', 'milan', 'other'], true)) {
                $region = $this->guessRegionFromText($summary.' '.$block);
            }

            $places = [];
            $kind = 'program';
            $model = null;
            $productId = null;
            foreach (array_slice($lines, 1) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^Места:\s*(.+)$/iu', $line, $m)) {
                    $parts = preg_split('/\s*,\s*/u', $m[1]) ?: [];
                    foreach ($parts as $p) {
                        $p = trim((string) $p);
                        if ($p !== '') {
                            $places[] = $p;
                        }
                    }
                } elseif (preg_match('/^Meta:\s*(.+)$/iu', $line, $m)) {
                    $metaLine = $m[1];
                    if (preg_match('/\b(arrival|program|departure|transfer)\b/iu', $metaLine, $km)) {
                        $kind = strtolower($km[1]);
                    }
                    if (preg_match('/model\s+([^\s·]+)/iu', $metaLine, $mm)) {
                        $model = $mm[1];
                    }
                    if (preg_match('/#(\d+)/u', $metaLine, $pm)) {
                        $productId = (int) $pm[1];
                    }
                }
            }
            // Body: everything after first "Текст:" line, or leftover lines
            $inBody = false;
            $bodyParts = [];
            foreach (array_slice($lines, 1) as $line) {
                $lineTrim = trim((string) $line);
                if (preg_match('/^Текст:\s*(.*)$/iu', $lineTrim, $m)) {
                    $inBody = true;
                    if (trim($m[1]) !== '') {
                        $bodyParts[] = trim($m[1]);
                    }

                    continue;
                }
                if ($inBody) {
                    $bodyParts[] = $lineTrim;

                    continue;
                }
                if (preg_match('/^(Места|Meta):/iu', $lineTrim) === 1) {
                    continue;
                }
                $bodyParts[] = $lineTrim;
            }
            $body = trim(implode("\n", array_filter($bodyParts, fn ($l) => $l !== '')));
            if ($places === []) {
                $places = [$summary];
            }
            $n++;
            $out[] = [
                'id' => 'edit_'.$n,
                'product_id' => $productId,
                'model' => $model,
                'places' => $places,
                'region' => $region,
                'summary' => $summary,
                'kind' => $kind,
                'body' => $body,
                'fit' => true,
            ];
        }

        return $out;
    }

    /**
     * Gemini: full relevant RU catalog of visit days (NOT an N-day itinerary).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  list<string>  $planLocations
     * @return array{
     *   days: list<array<string, mixed>>,
     *   dropped: list<array<string, mixed>>,
     *   note: string,
     *   ms: int
     * }
     */
    public function cleanPool(array $days, array $planLocations = []): array
    {
        $t0 = microtime(true);
        if ($days === []) {
            throw new RuntimeException('Пустой пул дней для очистки');
        }

        $compact = [];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $body = trim((string) ($day['body'] ?? ''));
            if (mb_strlen($body) > 1200) {
                $body = mb_substr($body, 0, 1200).'…';
            }
            $id = trim((string) ($day['id'] ?? ''));
            if ($id === '') {
                $id = 'd'.($i + 1);
            }
            $compact[] = [
                'id' => $id,
                'region' => $day['region'] ?? 'other',
                'kind' => $day['kind'] ?? 'program',
                'summary' => $day['summary'] ?? '',
                'places' => $day['places'] ?? [],
                'body' => $body,
                'model' => $day['model'] ?? null,
                'product_id' => $day['product_id'] ?? null,
            ];
        }

        if ($compact === []) {
            throw new RuntimeException('Пустой пул дней для очистки');
        }

        $beforeCount = count($compact);
        $compact = $this->dedupeBySense($compact);
        $phpNote = 'PHP-дедуп по смыслу мест: '.$beforeCount.' → '.count($compact).'. ';

        $workIds = [];
        foreach ($compact as $card) {
            $workIds[(string) $card['id']] = $card;
        }

        $batches = count($compact) > 45
            ? array_chunk($compact, 40)
            : [$compact];

        $cleaned = [];
        $batchNotes = [];
        foreach ($batches as $batch) {
            $part = $this->catalogBatchViaGemini($batch, $planLocations);
            foreach ($part['days'] as $day) {
                $cleaned[] = $day;
            }
            if ($part['note'] !== '') {
                $batchNotes[] = $part['note'];
            }
        }

        $cleaned = $this->dedupeBySense($cleaned);
        if ($cleaned === []) {
            throw new RuntimeException('После очистки не осталось дней');
        }

        $keptIds = array_fill_keys(array_map(fn ($d) => (string) ($d['id'] ?? ''), $cleaned), true);
        $dropped = [];
        foreach ($workIds as $card) {
            $id = (string) $card['id'];
            if (isset($keptIds[$id])) {
                continue;
            }
            $card['fit'] = false;
            $card['drop_reason'] = 'убрано при формировании релевантного списка';
            $dropped[] = $card;
        }

        $note = $phpNote.trim(implode(' ', $batchNotes));
        if (trim(str_replace($phpNote, '', $note)) === '') {
            $note = $phpNote.'Релевантный RU-каталог: '.count($cleaned).' дн. (в работе '
                .count($workIds).', убрано '.count($dropped).'). Не маршрут — полный список посещений.';
        }

        return [
            'days' => $this->groupDaysByRegion($cleaned),
            'dropped' => $dropped,
            'note' => $note,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Stable order: same region cards stay together (como → garda → dolomiti → …).
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    public function groupDaysByRegion(array $days): array
    {
        $order = ['como' => 0, 'garda' => 1, 'dolomiti' => 2, 'milan' => 3, 'tuscany' => 4, 'rome' => 5, 'other' => 6];
        $indexed = [];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $region = strtolower(trim((string) ($day['region'] ?? 'other')));
            if (! array_key_exists($region, $order)) {
                $region = 'other';
            }
            $indexed[] = ['i' => $i, 'rank' => $order[$region], 'day' => $day];
        }

        usort($indexed, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }

            return $a['i'] <=> $b['i'];
        });

        return array_values(array_map(fn (array $row) => $row['day'], $indexed));
    }

    /**
     * @param  list<array<string, mixed>>  $compact
     * @param  list<string>  $planLocations
     * @return array{days: list<array<string, mixed>>, note: string}
     */
    private function catalogBatchViaGemini(array $compact, array $planLocations = []): array
    {
        $planHint = $planLocations !== []
            ? ('Локации плана (оставляй только то, что к ним относится): '.implode(', ', array_slice($planLocations, 0, 24)).'.')
            : 'Оставляй дни, похожие на маршрутные посещения по Италии (озёра/горы/города из сырья).';

        $instruction = <<<PROMPT
Ты — редактор каталога дней для будущего маршрута по Италии.
Дан список карточек (days). Задача: выдать РЕЛЕВАНТНЫЙ ПОЛНЫЙ СПИСОК посещений на русском.

ВАЖНО:
- НЕ собирай маршрут на 10 дней и НЕ выбирай «лучший» календарь.
- Верни ВСЕ годные уникальные варианты посещений из входа (каталог), не ужимай под N дней.
- Убери мусор: TOC, FAQ, аренда авто, пустые дни без мест.
- Убери почти-дубли по смыслу мест (даты из чужих туров не важны — «Limone 26/7» и «Limone 12/7» = одна карточка).
- Перепиши summary и body нормальным русским (без иврита), кратко. Места не выдумывай.
- {$planHint}
- region: garda|dolomiti|como|tuscany|rome|milan|other. kind: arrival|program|departure|transfer.
- Ответ только JSON:
{"days":[{"id":"...","region":"garda","kind":"program","summary":"...","places":["..."],"body":"...","model":null,"product_id":null}],"dropped_ids":["..."],"note":"1–2 предложения по-русски"}
PROMPT;

        $payload = json_encode([
            'plan_locations' => array_values($planLocations),
            'days' => $compact,
            'instructions' => [
                'Полный релевантный RU-каталог посещений.',
                'НЕ собирать маршрут на target_days.',
                'Дубли по смыслу мест — одна карточка.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $model = trim((string) config('services.itinerary.formatter_model', config('services.gemini.model')));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        $raw = $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $instruction, $payload) {
            return $this->chatJson($apiKey, $model, $instruction, (string) $payload, $timeout);
        });
        $decoded = $this->decodeJson($raw);
        $cleanedRaw = is_array($decoded['days'] ?? null) ? $decoded['days'] : [];
        if ($cleanedRaw === []) {
            throw new RuntimeException('Gemini вернул пустой релевантный список');
        }

        $byId = [];
        foreach ($compact as $card) {
            $byId[(string) $card['id']] = $card;
        }

        $cleaned = [];
        foreach ($cleanedRaw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $src = $id !== '' && isset($byId[$id]) ? $byId[$id] : [];
            $places = [];
            foreach (($row['places'] ?? []) as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $places[] = $p;
                }
            }
            if ($places === [] && is_array($src['places'] ?? null)) {
                $places = $src['places'];
            }
            $summary = trim((string) ($row['summary'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            if ($summary === '' && $places === [] && $body === '') {
                continue;
            }
            $region = strtolower(trim((string) ($row['region'] ?? ($src['region'] ?? 'other'))));
            if (! in_array($region, ['garda', 'dolomiti', 'como', 'tuscany', 'rome', 'milan', 'other'], true)) {
                $region = 'other';
            }
            $kind = strtolower(trim((string) ($row['kind'] ?? ($src['kind'] ?? 'program'))));
            if (! in_array($kind, ['arrival', 'program', 'departure', 'transfer'], true)) {
                $kind = 'program';
            }
            $cleaned[] = [
                'id' => $id !== '' ? $id : ('c'.(count($cleaned) + 1)),
                'region' => $region,
                'kind' => $kind,
                'summary' => $summary !== '' ? $summary : ($places[0] ?? 'день'),
                'places' => $places,
                'body' => $body !== '' ? $body : (string) ($src['body'] ?? ''),
                'model' => $row['model'] ?? ($src['model'] ?? null),
                'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : ($src['product_id'] ?? null),
                'fit' => true,
            ];
        }

        return [
            'days' => $cleaned,
            'note' => trim((string) ($decoded['note'] ?? '')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    public function formatCuratedText(array $days): string
    {
        $chunks = [];
        foreach (array_values($days) as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $places = is_array($day['places'] ?? null) ? implode(', ', $day['places']) : '';
            $lines = [
                ($i + 1).'. ['.($day['region'] ?? 'other').'] '.((string) ($day['summary'] ?? '') !== '' ? (string) $day['summary'] : ($places !== '' ? $places : 'день')),
            ];
            if ($places !== '') {
                $lines[] = 'Места: '.$places;
            }
            if (isset($day['lat'], $day['lng']) && $day['lat'] !== null && $day['lng'] !== null) {
                $geoLine = 'Координаты: '.(float) $day['lat'].', '.(float) $day['lng'];
                if (isset($day['geo_span_km']) && is_numeric($day['geo_span_km'])) {
                    $geoLine .= ' · разброс '.(float) $day['geo_span_km'].' км';
                    if (array_key_exists('geo_ok', $day) && $day['geo_ok'] === false) {
                        $geoLine .= ' (далеко внутри дня)';
                    }
                }
                $lines[] = $geoLine;
            }
            if (is_array($day['places_geo'] ?? null) && $day['places_geo'] !== []) {
                $placeCoords = [];
                foreach ($day['places_geo'] as $pg) {
                    if (! is_array($pg)) {
                        continue;
                    }
                    $placeCoords[] = ($pg['name'] ?? '?').' ('
                        .(isset($pg['lat']) ? (float) $pg['lat'] : '?').', '
                        .(isset($pg['lng']) ? (float) $pg['lng'] : '?').')';
                }
                if ($placeCoords !== []) {
                    $lines[] = 'Точки: '.implode('; ', $placeCoords);
                }
            }
            $meta = [];
            if (! empty($day['kind'])) {
                $meta[] = (string) $day['kind'];
            }
            if (! empty($day['model'])) {
                $meta[] = 'model '.$day['model'];
            }
            if (! empty($day['product_id'])) {
                $meta[] = '#'.$day['product_id'];
            }
            if ($meta !== []) {
                $lines[] = 'Meta: '.implode(' · ', $meta);
            }
            $body = trim((string) ($day['body'] ?? ''));
            if ($body !== '') {
                $lines[] = 'Текст:';
                $lines[] = $body;
            }
            $chunks[] = implode("\n", $lines);
        }

        return implode("\n\n", $chunks);
    }

    /**
     * @return list<string>
     */
    public function detectPreferredRegions(string $question): array
    {
        $q = mb_strtolower($question);
        $map = [
            'garda' => '/гард|garda|сирмион|лимоне|рива|tenno|тенно|мольвено|molveno/u',
            'dolomiti' => '/доломит|ортизеи|ortisei|альп[еа]-?\s*ди|val\s*gardena/u',
            'como' => '/комо|como|белладж|вар[еэ]нн|лекк/u',
            'tuscany' => '/тоскан|tuscany|пьенц|кьянт|флоренц|джиминьян|чиан/u',
            'rome' => '/\bрим\b|rome|viterbo|витербо/u',
            'milan' => '/милан|milan|мальпенс/u',
        ];
        $out = [];
        foreach ($map as $region => $re) {
            if (preg_match($re, $q) === 1) {
                $out[] = $region;
            }
        }
        // Nature / no big cities → keep lake+mountains in priority even if hub is Milan
        if (preg_match('/природ|nature|без\s+больш/u', $q) === 1) {
            foreach (['garda', 'dolomiti'] as $r) {
                if (! in_array($r, $out, true)) {
                    $out[] = $r;
                }
            }
            // Hub city alone is not a program region
            $out = array_values(array_filter($out, static fn (string $r) => $r !== 'milan'));
        }
        if ($out === []) {
            $out = ['garda', 'dolomiti'];
        }

        return $out;
    }

    /**
     * PHP prep before Gemini assemble: drop junk/vague/entertainment, sense-dedupe,
     * cap side regions, keep enough cards from each preferred region present in the pool.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array{days: list<array<string, mixed>>, preferred_regions: list<string>, before: int, note: string}
     */
    public function prepareAssemblePool(string $question, array $days): array
    {
        $before = count($days);
        $constraints = $this->briefParser->parse($question);
        $nature = ! empty($constraints['nature']) || ! empty($constraints['no_big_cities']);
        $preferred = $this->detectPreferredRegions($question);
        $targetDays = max(3, (int) ($constraints['days'] ?? 10));

        $normalized = [];
        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $region = strtolower(trim((string) ($day['region'] ?? 'other')));
            if (! in_array($region, ['garda', 'dolomiti', 'como', 'tuscany', 'rome', 'milan', 'other'], true)) {
                $region = 'other';
            }
            if ($region === 'other') {
                $region = $this->guessRegionFromText(
                    (string) ($day['summary'] ?? '').' '.implode(' ', $day['places'] ?? []).' '.(string) ($day['body'] ?? '')
                );
            }
            $day['region'] = $region;
            $kind = strtolower(trim((string) ($day['kind'] ?? 'program')));
            if (! in_array($kind, ['arrival', 'program', 'departure', 'transfer'], true)) {
                $kind = 'program';
            }
            $day['kind'] = $kind;

            if ($nature && $this->isEntertainmentDay($day) && ! in_array($kind, ['arrival', 'departure', 'transfer'], true)) {
                continue;
            }
            if ($this->isVagueDay($day) && $kind === 'program') {
                continue;
            }
            // Drop program cards that mix distant places (e.g. north Garda + south Garda/Como).
            if ($kind === 'program'
                && array_key_exists('geo_ok', $day)
                && $day['geo_ok'] === false
                && isset($day['geo_span_km'])
                && is_numeric($day['geo_span_km'])) {
                continue;
            }
            $normalized[] = $day;
        }

        $normalized = $this->dedupeBySense($normalized);

        $present = [];
        foreach ($normalized as $day) {
            $r = (string) ($day['region'] ?? 'other');
            if ($r !== 'other') {
                $present[$r] = true;
            }
        }
        if ($preferred === []) {
            $preferred = array_keys($present);
        }
        // Preferred that actually exist in pool (order preserved)
        $preferredPresent = [];
        foreach ($preferred as $r) {
            if (isset($present[$r])) {
                $preferredPresent[] = $r;
            }
        }
        if ($preferredPresent === [] && $present !== []) {
            $preferredPresent = array_keys($present);
        }

        $byRegion = [];
        foreach ($normalized as $day) {
            $r = (string) ($day['region'] ?? 'other');
            $byRegion[$r][] = $day;
        }
        foreach ($byRegion as $r => &$list) {
            usort($list, function (array $a, array $b) {
                $ka = in_array(($a['kind'] ?? ''), ['arrival', 'departure', 'transfer'], true) ? 0 : 1;
                $kb = in_array(($b['kind'] ?? ''), ['arrival', 'departure', 'transfer'], true) ? 0 : 1;
                if ($ka !== $kb) {
                    return $ka <=> $kb;
                }
                $ga = ! empty($a['geo_ok']) ? 1 : 0;
                $gb = ! empty($b['geo_ok']) ? 1 : 0;
                if ($ga !== $gb) {
                    return $gb <=> $ga;
                }
                $sa = $this->specificityScore($a);
                $sb = $this->specificityScore($b);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }

                return count($b['places'] ?? []) <=> count($a['places'] ?? []);
            });
        }
        unset($list);

        // Near-duplicate cards that share concrete places (Tenno+Varone+Riva twice → one)
        foreach ($byRegion as $r => $list) {
            $byRegion[$r] = $this->dedupeBySharedPlaces($list);
        }

        $primaryCap = max(8, (int) ceil($targetDays * 1.2));
        $sideCap = $nature ? 1 : 2; // nature: side regions almost out
        $out = [];
        $used = [];

        // Ensure each preferred-present region contributes cards
        foreach ($preferredPresent as $r) {
            $take = array_slice($byRegion[$r] ?? [], 0, $primaryCap);
            foreach ($take as $day) {
                $id = (string) ($day['id'] ?? md5(json_encode($day)));
                if (isset($used[$id])) {
                    continue;
                }
                $used[$id] = true;
                $out[] = $day;
            }
        }

        // Side regions (present but not preferred): hard cap
        foreach ($byRegion as $r => $list) {
            if (in_array($r, $preferredPresent, true)) {
                continue;
            }
            $cap = $r === 'milan' ? 1 : $sideCap;
            if ($nature && in_array($r, ['como', 'tuscany', 'rome'], true)) {
                $cap = 1; // at most one side-lake/city card
            }
            foreach (array_slice($list, 0, $cap) as $day) {
                $id = (string) ($day['id'] ?? md5(json_encode($day)));
                if (isset($used[$id])) {
                    continue;
                }
                $used[$id] = true;
                $out[] = $day;
            }
        }

        // Soft global cap for Gemini context
        $maxCards = max(36, $targetDays * 5);
        if (count($out) > $maxCards) {
            $out = array_slice($out, 0, $maxCards);
        }

        $note = 'PHP-отбор перед сборкой: '.$before.' → '.count($out)
            .' карт. Приоритет region: '.($preferredPresent !== [] ? implode(', ', $preferredPresent) : '—')
            .'; побочные ≤'.$sideCap.'; конкретные places выше размытых.';

        return [
            'days' => $out,
            'preferred_regions' => $preferredPresent,
            'before' => $before,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function isEntertainmentDay(array $day): bool
    {
        $blob = mb_strtolower(
            (string) ($day['summary'] ?? '').' '
            .implode(' ', $day['places'] ?? []).' '
            .(string) ($day['body'] ?? '')
        );

        return preg_match(
            '/аутлет|outlet|шопинг|shopping|gardaland|movieland|аквапарк|aquapark|caneva|алпиленд|alpiland|картинг|karting|зоопарк|safari|sigurt[aа]|сигурта/u',
            $blob
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function isVagueDay(array $day): bool
    {
        $places = [];
        foreach (($day['places'] ?? []) as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $places[] = $p;
            }
        }
        $summary = trim((string) ($day['summary'] ?? ''));
        $blob = mb_strtolower($summary.' '.implode(' ', $places));
        if ($places === [] && mb_strlen($summary) < 12) {
            return true;
        }
        if ($this->isGenericPlaceLabel($blob)) {
            return true;
        }
        $concrete = 0;
        foreach ($places as $p) {
            if (! $this->isGenericPlaceLabel(mb_strtolower($p))) {
                $concrete++;
            }
        }
        // All place labels are generic region names
        if ($places !== [] && $concrete === 0) {
            return true;
        }

        return false;
    }

    private function isGenericPlaceLabel(string $label): bool
    {
        $label = trim($label);
        if ($label === '') {
            return true;
        }

        return preg_match(
            '/^(доломитов\w*(\s+альп\w*)?|оз[её]ра?\s+доломит\w*|северн\w*\s+гард\w*|озеро\s+гард\w*|озеро\s+комо|гард\w*|комо|итали\w*|альп\w*|горы|водопады\s+рива)$/u',
            $label
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function specificityScore(array $day): int
    {
        $score = 0;
        foreach (($day['places'] ?? []) as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if ($this->isGenericPlaceLabel(mb_strtolower($p))) {
                $score -= 2;

                continue;
            }
            $score += 3 + min(4, (int) floor(mb_strlen($p) / 8));
        }
        $summary = trim((string) ($day['summary'] ?? ''));
        if ($summary !== '' && ! $this->isGenericPlaceLabel(mb_strtolower($summary))) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Drop near-duplicate cards that share ≥2 concrete places.
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function dedupeBySharedPlaces(array $days): array
    {
        $out = [];
        /** @var list<list<string>> $placeSets */
        $placeSets = [];
        foreach ($days as $day) {
            $places = [];
            foreach (($day['places'] ?? []) as $p) {
                $p = mb_strtolower(trim((string) $p));
                if ($p === '' || $this->isGenericPlaceLabel($p)) {
                    continue;
                }
                $places[] = $p;
            }
            $places = array_values(array_unique($places));
            sort($places);
            $dup = false;
            foreach ($placeSets as $i => $existing) {
                $shared = count(array_intersect($existing, $places));
                if ($shared >= 2 || ($shared >= 1 && count($places) <= 2 && count($existing) <= 2)) {
                    // Keep higher specificity
                    if ($this->specificityScore($day) > $this->specificityScore($out[$i])) {
                        $out[$i] = $day;
                        $placeSets[$i] = $places;
                    }
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }
            $placeSets[] = $places;
            $out[] = $day;
        }

        return $out;
    }

    /**
     * @param  array{product_id: int|null, model: string|null, source_index: int}  $meta
     * @return list<array<string, mixed>>
     */
    private function extractDaysFromText(string $text, array $meta): array
    {
        $model = trim((string) config('services.itinerary.formatter_model', config('services.gemini.model')));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        $instruction = <<<'PROMPT'
Extract ALL itinerary route days from the tour text (Hebrew and/or Russian OK).
Include: every program day, overnight/hotel near airport, arrival, departure, transfers with places.
Ignore ONLY junk: table of contents, FAQ, legal disclaimers (Gimpel), car-rental ads, driving tips.
Do NOT filter by client preferences — return every route day you find.
Return JSON only:
{"days":[{"places":["place1","place2"],"region":"garda|dolomiti|como|tuscany|rome|milan|other","summary":"short Russian 3-8 words","kind":"arrival|program|departure|transfer","body":"full day text in Russian if possible, keep place names and useful details"}]}
Rules:
- One object per day. Keep body: the day's content (not just the title).
- places from that day; translate names to Russian when possible.
- region by geography (Tenno/Riva/Limone/Garda→garda; Ortisei→dolomiti; Pienza/Florence→tuscany; Varenna/Bellagio→como; Rome→rome; Milan/MXP→milan).
- Hebrew day headers like "יום ..." count as days.
PROMPT;

        $payload = json_encode([
            'source' => [
                'product_id' => $meta['product_id'],
                'model' => $meta['model'],
                'text' => $text,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $raw = $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $instruction, $payload) {
            return $this->chatJson($apiKey, $model, $instruction, (string) $payload, $timeout);
        });

        $decoded = $this->decodeJson($raw);
        $list = is_array($decoded['days'] ?? null) ? $decoded['days'] : [];
        $out = [];
        $n = 0;
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $places = [];
            foreach (($row['places'] ?? []) as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $places[] = $p;
                }
            }
            $summary = trim((string) ($row['summary'] ?? ''));
            $body = trim((string) ($row['body'] ?? $row['text'] ?? ''));
            if ($places === [] && $summary === '' && $body === '') {
                continue;
            }
            if ($summary === '') {
                $summary = implode(', ', array_slice($places, 0, 4));
            }
            if ($summary === '' && $body !== '') {
                $summary = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 80);
            }
            $region = strtolower(trim((string) ($row['region'] ?? 'other')));
            if (! in_array($region, ['garda', 'dolomiti', 'como', 'tuscany', 'rome', 'milan', 'other'], true)) {
                $region = $this->guessRegionFromText($summary.' '.$body.' '.implode(' ', $places));
            }
            $kind = strtolower(trim((string) ($row['kind'] ?? 'program')));
            if (! in_array($kind, ['arrival', 'program', 'departure', 'transfer'], true)) {
                $kind = 'program';
            }
            if (mb_strlen($body) > 4000) {
                $body = mb_substr($body, 0, 4000).'…';
            }
            $n++;
            $out[] = [
                'id' => 's'.$meta['source_index'].'_d'.$n,
                'product_id' => $meta['product_id'],
                'model' => $meta['model'],
                'places' => $places,
                'region' => $region,
                'summary' => $summary,
                'kind' => $kind,
                'body' => $body,
                'fit' => true,
            ];
        }

        return $out;
    }

    /**
     * Regex fallback when Gemini returns no days (common for HE-only sources).
     *
     * @param  array{product_id: int|null, model: string|null, source_index: int}  $meta
     * @return list<array<string, mixed>>
     */
    private function extractDaysHeuristic(string $text, array $meta): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $headers = [];
        $heWeek = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        $ruWeek = 'Понедельник|Вторник|Среда|Четверг|Пятница|Суббота|Воскресенье';

        foreach ($lines as $idx => $line) {
            $line = trim((string) $line);
            if ($line === '' || mb_strlen($line) > 180) {
                continue;
            }
            if (str_contains($line, '#_Toc')) {
                continue;
            }
            // Skip dense TOC: many dates + trailing page number only
            if (preg_match('/\d{1,2}[\/.]\d{1,2}.+\d{1,3}\s*$/u', $line) === 1
                && mb_strlen($line) < 80
                && substr_count($line, '/') + substr_count($line, '.') <= 4) {
                // still allow as header if looks like day title
            }

            $isHeader = false;
            if (preg_match('/^(?:יום)\s+(?:'.$heWeek.'|\d{1,2})\b/u', $line) === 1) {
                $isHeader = true;
            } elseif (preg_match('/^(?:'.$ruWeek.')\s*[-–—]?\s*\d{1,2}[\/.]\d{1,2}/u', $line) === 1) {
                $isHeader = true;
            } elseif (preg_match('/^\d{1,2}[\/.]\d{1,2}\s*[-–—]/u', $line) === 1) {
                $isHeader = true;
            }

            if ($isHeader) {
                $headers[] = $idx;
            }
        }

        if ($headers === []) {
            return [];
        }

        $out = [];
        $total = count($lines);
        foreach ($headers as $h => $start) {
            $end = $headers[$h + 1] ?? $total;
            $chunkLines = array_slice($lines, $start, max(1, $end - $start));
            // Cap very long day chunks
            if (count($chunkLines) > 80) {
                $chunkLines = array_slice($chunkLines, 0, 80);
            }
            $chunk = trim(implode("\n", $chunkLines));
            if ($this->isNoiseChunk($chunk)) {
                continue;
            }
            $header = trim((string) ($chunkLines[0] ?? ''));
            $bodyLines = array_slice($chunkLines, 1);
            $body = trim(implode("\n", $bodyLines));
            if (mb_strlen($body) > 4000) {
                $body = mb_substr($body, 0, 4000).'…';
            }
            $places = $this->placesFromHeader($header);
            if ($places === [] && $body !== '') {
                $firstBody = trim((string) ($bodyLines[0] ?? ''));
                if ($firstBody !== '') {
                    $places = [mb_substr($firstBody, 0, 120)];
                }
            }
            $blob = $header.' '.$body;
            $region = $this->guessRegionFromText($blob);
            $kind = 'program';
            if (preg_match('/аэропорт|airport|נחיתה|טיסה|מלפנסה|милан.*ночев|לינה.*שדה|ночевк/ui', $blob) === 1
                && preg_match('/озер|אגם|водопад|מפל|ортизеи|тенно|мольвено/ui', $blob) !== 1) {
                $kind = preg_match('/вылет|חזרה|departure|טיסת חזרה/ui', $blob) === 1 ? 'departure' : 'arrival';
            }

            $summary = $header;
            if (mb_strlen($summary) > 120) {
                $summary = mb_substr($summary, 0, 120).'…';
            }

            $out[] = [
                'id' => 's'.$meta['source_index'].'_h'.($h + 1),
                'product_id' => $meta['product_id'],
                'model' => $meta['model'],
                'places' => $places,
                'region' => $region,
                'summary' => $summary,
                'kind' => $kind,
                'body' => $body,
                'fit' => true,
            ];
        }

        return $out;
    }

    private function preferProgramSlice(string $text, int $maxChars): string
    {
        // Drop repeated legal blurb only — keep route content
        $text = (string) preg_replace('/Цель маршрутов[\s\S]{0,900}?Гимпель[^\n]{0,200}/u', "\n", $text);
        $text = (string) preg_replace('/מטרת המסלולים[\s\S]{0,900}?גימפל[^\n]{0,200}/u', "\n", $text);
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars)."\n…";
        }

        return trim($text);
    }

    private function isNoiseChunk(string $chunk): bool
    {
        $sample = mb_substr($chunk, 0, 500);
        if (preg_match('/Цель маршрутов|מטרת המסלולים|Гимпель|גימפל|Как правильно читать|איך לקרוא/u', $sample) === 1) {
            return true;
        }
        if (preg_match('/Эксклюзивные предложения по аренде|הצעות בלעדיות להשכרת/u', $sample) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function placesFromHeader(string $header): array
    {
        $header = preg_replace('/\d{1,3}\s*$/u', '', $header) ?? $header;
        $header = preg_replace('/^(?:יום)\s+(?:ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת|\d{1,2})\s*[-–—:]?\s*/u', '', $header) ?? $header;
        $header = preg_replace('/^(?:Понедельник|Вторник|Среда|Четверг|Пятница|Суббота|Воскресенье)\s*[-–—]?\s*/u', '', $header) ?? $header;
        $header = preg_replace('/^\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?\s*[-–—:]?\s*/u', '', $header) ?? $header;
        $header = trim($header, " \t-–—:");
        if ($header === '') {
            return [];
        }
        $parts = preg_split('/\s*[, ،،]\s*|\s+и\s+/u', $header) ?: [$header];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '' && mb_strlen($p) < 80) {
                $out[] = $p;
            }
        }

        return $out === [] ? [$header] : array_slice($out, 0, 6);
    }

    private function guessRegionFromText(string $text): string
    {
        $t = mb_strtolower($text);
        $map = [
            'garda' => '/гард|garda|tenno|тенно|תנו|рива|riva|лимоне|limone|сирмион|sirmione|боргетто|borghetto|мольвено|molveno|וורונה|varone|נרדיס|nardis|אגם גארדה/u',
            'dolomiti' => '/доломит|ортизеи|ortisei|альпе|alpe|siusi|сьюз|val gardena|אורטיזיי/u',
            'como' => '/комо|como|белладж|bellagio|вар[еэ]нн|varenna|лекк|lecco|ורנה|בלאג/u',
            'tuscany' => '/тоскан|tuscany|пьенц|pienza|кьянт|chianti|флоренц|florence|джиминьян|gimignano|פיאנצה|טוסקנה/u',
            'rome' => '/\bрим\b|rome|roma|viterbo|витербо|רומא/u',
            'milan' => '/милан|milan|мальпенс|malpensa|mxp|מילאנו|מלפנסה/u',
        ];
        foreach ($map as $region => $re) {
            if (preg_match($re, $t) === 1) {
                return $region;
            }
        }

        return 'other';
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function dedupeOnly(array $days): array
    {
        return $this->dedupeBySense($days);
    }

    /**
     * Same places / same visit sense → one card (dates from foreign tours ignored).
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function dedupeBySense(array $days): array
    {
        $out = [];
        $seen = [];
        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            if (($day['region'] ?? 'other') === 'other') {
                $day['region'] = $this->guessRegionFromText(
                    (string) ($day['summary'] ?? '').' '.implode(' ', $day['places'] ?? []).' '.(string) ($day['body'] ?? '')
                );
            }
            $fp = $this->senseFingerprint($day);
            if (isset($seen[$fp])) {
                $idx = $seen[$fp];
                $existing = $out[$idx];
                $a = (string) ($existing['body'] ?? $existing['summary'] ?? '');
                $b = (string) ($day['body'] ?? $day['summary'] ?? '');
                if (mb_strlen($b) > mb_strlen($a)) {
                    $out[$idx] = $day;
                }

                continue;
            }
            $seen[$fp] = count($out);
            $out[] = $day;
        }

        return $out;
    }

    /**
     * Strip weekdays/dates so «26/7 Limone» ≈ «12/7 Limone».
     */
    private function stripTemporalNoise(string $text): string
    {
        $heWeek = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        $ruWeek = 'Понедельник|Вторник|Среда|Четверг|Пятница|Суббота|Воскресенье';
        $text = preg_replace('/^(?:יום)\s+(?:'.$heWeek.'|\d{1,2})\s*[-–—:]?\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:'.$ruWeek.')\s*[-–—:]?\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:day|день)\s+\d{1,2}\s*[-–—:]?\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/\b\d{1,2}\s*[.\/,]\s*\d{1,2}(?:\s*[.\/,]\s*\d{2,4})?\b/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text, " \t-–—:·|");
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function senseFingerprint(array $day): string
    {
        $places = [];
        foreach (($day['places'] ?? []) as $p) {
            $p = $this->stripTemporalNoise(mb_strtolower(trim((string) $p)));
            if ($p !== '' && mb_strlen($p) >= 3) {
                $places[] = $p;
            }
        }
        $places = array_values(array_unique($places));
        sort($places);
        if ($places !== []) {
            return md5(implode('|', array_slice($places, 0, 5)));
        }

        $blob = $this->stripTemporalNoise(mb_strtolower(
            trim((string) ($day['summary'] ?? '').' '.(string) ($day['body'] ?? ''))
        ));

        return md5(mb_substr($blob, 0, 120));
    }

    /**
     * Brief-aware selection: Gemini decides keep/drop; PHP rules as fallback.
     *
     * @param  array<string, mixed>  $constraints
     * @param  list<string>  $preferred
     * @param  list<array<string, mixed>>  $candidates
     * @return array{kept: list<array<string, mixed>>, dropped: list<array<string, mixed>>, brief_note: string}
     */
    private function selectAgainstBrief(string $question, array $constraints, array $preferred, array $candidates): array
    {
        if ($candidates === []) {
            return [
                'kept' => [],
                'dropped' => [],
                'brief_note' => 'Нет кандидатов дней для отбора.',
            ];
        }

        $asked = isset($constraints['days']) && is_int($constraints['days']) ? $constraints['days'] : null;
        $briefNote = 'Опросник: '.$question
            .($preferred !== [] ? ' · регионы: '.implode(', ', $preferred) : '')
            .(($constraints['no_big_cities'] ?? false) ? ' · без больших городов' : '')
            .(($constraints['nature'] ?? false) ? ' · природа' : '')
            .($asked ? ' · ~'.$asked.' дн.' : '');

        try {
            $picked = $this->geminiSelectKeepIds($question, $constraints, $preferred, $candidates, $asked);
            if ($picked['keep_ids'] !== []) {
                return $this->splitByIds($candidates, $picked['keep_ids'], $picked['note'] ?: $briefNote);
            }
        } catch (\Throwable $e) {
            Log::warning('Brief select via Gemini failed, using rules', ['message' => $e->getMessage()]);
        }

        return $this->ruleSelectAgainstBrief($candidates, $preferred, $constraints, $briefNote, $asked);
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  list<string>  $preferred
     * @param  list<array<string, mixed>>  $candidates
     * @return array{keep_ids: list<string>, note: string}
     */
    private function geminiSelectKeepIds(
        string $question,
        array $constraints,
        array $preferred,
        array $candidates,
        ?int $asked,
    ): array {
        $model = trim((string) config('services.itinerary.formatter_model', config('services.gemini.model')));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        $cards = [];
        foreach ($candidates as $day) {
            $cards[] = [
                'id' => $day['id'] ?? null,
                'region' => $day['region'] ?? 'other',
                'kind' => $day['kind'] ?? 'program',
                'summary' => $day['summary'] ?? '',
                'places' => $day['places'] ?? [],
            ];
        }

        $instruction = <<<'PROMPT'
Ты — редактор сырья для маршрута по Италии.
Дан опросник клиента и список карточек дней (candidates).
Задача: оставить ТОЛЬКО дни, которые реально подходят под опросник.

Правила:
1) Если в опроснике Гарда/природа/без больших городов — НЕ бери Милан sightseeing, Тоскану, Рим, Парму, Модену, аутлеты/шопинг.
2) Можно оставить 1 arrival и 1 departure, если они логичны (аэропорт).
3) Предпочти регионы из preferred_regions.
4) Без дублей похожих дней (Тенно+Мольвено дважды — оставь лучший).
5) Если target_days задан — держи примерно столько program-дней (+ arrival/departure).
6) Ответ только JSON:
{"keep_ids":["id1","id2"],"drop_ids":["id3"],"note":"1–2 предложения по-русски: что оставили и почему"}
PROMPT;

        $payload = json_encode([
            'question' => $question,
            'constraints' => $constraints,
            'preferred_regions' => $preferred,
            'target_days' => $asked,
            'candidates' => $cards,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $raw = $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $instruction, $payload) {
            return $this->chatJson($apiKey, $model, $instruction, (string) $payload, $timeout);
        });
        $decoded = $this->decodeJson($raw);
        $keepIds = [];
        foreach (($decoded['keep_ids'] ?? []) as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $keepIds[] = $id;
            }
        }

        return [
            'keep_ids' => array_values(array_unique($keepIds)),
            'note' => trim((string) ($decoded['note'] ?? '')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $keepIds
     * @return array{kept: list<array<string, mixed>>, dropped: list<array<string, mixed>>, brief_note: string}
     */
    private function splitByIds(array $candidates, array $keepIds, string $note): array
    {
        $keepMap = array_fill_keys($keepIds, true);
        $kept = [];
        $dropped = [];
        foreach ($candidates as $day) {
            $id = (string) ($day['id'] ?? '');
            if ($id !== '' && isset($keepMap[$id])) {
                $day['fit'] = true;
                $kept[] = $day;
            } else {
                $day['fit'] = false;
                $day['drop_reason'] = 'не подходит под опросник';
                $dropped[] = $day;
            }
        }

        return [
            'kept' => $kept,
            'dropped' => $dropped,
            'brief_note' => $note,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $preferred
     * @param  array<string, mixed>  $constraints
     * @return array{kept: list<array<string, mixed>>, dropped: list<array<string, mixed>>, brief_note: string}
     */
    private function ruleSelectAgainstBrief(
        array $candidates,
        array $preferred,
        array $constraints,
        string $briefNote,
        ?int $asked,
    ): array {
        $noBig = (bool) ($constraints['no_big_cities'] ?? false);
        $nature = (bool) ($constraints['nature'] ?? false);
        $kept = [];
        $dropped = [];

        foreach ($candidates as $day) {
            $region = (string) ($day['region'] ?? 'other');
            $kind = (string) ($day['kind'] ?? 'program');
            $summary = mb_strtolower((string) ($day['summary'] ?? '').' '.implode(' ', $day['places'] ?? []));
            $reason = null;

            if ($preferred !== [] && $kind === 'program' && ! in_array($region, $preferred, true)) {
                $reason = 'регион '.$region.' вне опросника ('.implode('/', $preferred).')';
            }
            if ($reason === null && ($noBig || $nature) && preg_match('/аутлет|outlet|шопинг|shopping/u', $summary) === 1) {
                $reason = 'шопинг/аутлет при запросе природа/без больших городов';
            }
            if ($reason === null && ($noBig || $nature) && $kind === 'program'
                && in_array($region, ['milan', 'rome', 'tuscany'], true)
                && ! in_array($region, $preferred, true)) {
                $reason = 'большой город / другой регион';
            }

            if ($reason !== null) {
                $day['fit'] = false;
                $day['drop_reason'] = $reason;
                $dropped[] = $day;
            } else {
                $day['fit'] = true;
                $kept[] = $day;
            }
        }

        // If preferred regions yielded nothing, fall back to top nature/garda-ish cards
        if ($kept === [] && $candidates !== []) {
            foreach ($candidates as $day) {
                $region = (string) ($day['region'] ?? 'other');
                if (in_array($region, $preferred !== [] ? $preferred : ['garda', 'dolomiti'], true)
                    || ($day['kind'] ?? '') !== 'program') {
                    $day['fit'] = true;
                    unset($day['drop_reason']);
                    $kept[] = $day;
                }
            }
            $dropIds = array_fill_keys(array_map(fn ($d) => (string) ($d['id'] ?? ''), $kept), true);
            $dropped = [];
            foreach ($candidates as $day) {
                $id = (string) ($day['id'] ?? '');
                if ($id === '' || isset($dropIds[$id])) {
                    continue;
                }
                $day['fit'] = false;
                $day['drop_reason'] = 'запасной отбор';
                $dropped[] = $day;
            }
        }

        if ($asked !== null && $asked > 0) {
            $program = array_values(array_filter($kept, fn ($d) => ($d['kind'] ?? '') === 'program'));
            $edges = array_values(array_filter($kept, fn ($d) => ($d['kind'] ?? '') !== 'program'));
            if (count($program) > $asked) {
                $extra = array_slice($program, $asked);
                $program = array_slice($program, 0, $asked);
                foreach ($extra as $day) {
                    $day['fit'] = false;
                    $day['drop_reason'] = 'лишний день сверх запроса ~'.$asked;
                    $dropped[] = $day;
                }
                $kept = array_merge($edges, $program);
            }
        }

        return [
            'kept' => $kept,
            'dropped' => $dropped,
            'brief_note' => $briefNote.' · правило-фильтр (Gemini select недоступен/пуст)',
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function fingerprint(array $day): string
    {
        return $this->senseFingerprint($day);
    }

    private function chatJson(string $apiKey, string $model, string $instruction, string $material, int $timeout): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent?key='.$apiKey;

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $instruction."\n\n--- SOURCE ---\n".$material]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
            ],
        ];
        if (str_starts_with($model, 'gemini-3')) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
        }

        $response = Http::timeout($timeout)->acceptJson()->asJson()->post($url, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Curate Gemini HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400));
        }
        $parts = $response->json('candidates.0.content.parts') ?? [];
        $chunks = [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $chunks[] = (string) $part['text'];
                }
            }
        }
        $raw = trim(implode("\n", $chunks));
        if ($raw === '') {
            throw new RuntimeException('Empty curate response');
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Curate returned non-JSON');
        }

        return $decoded;
    }
}
