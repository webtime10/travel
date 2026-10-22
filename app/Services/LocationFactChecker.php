<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationFact;

class LocationFactChecker
{
    private const LABEL_WORDS = [
        'כתובת', 'לכתובת', 'קישור', 'לוויז', 'וויז', 'לחץ', 'כאן', 'שעות', 'פעילות', 'לאתר', 'למסלול', 'מומלצת', 'מומלץ', 'מומלצות',
        'למפה', 'מפה', 'אתר', 'לאתר', 'גוגל',
        'איך', 'להגיע', 'ניתן', 'באמצעות', 'תחבורה', 'ציבורית', 'הציבורית', 'הבאה', 'יציאה', 'בשעות', 'השעה', 'כרטיסים', 'ומידע',
        'address', 'link', 'click', 'here', 'waze', 'hours', 'opening', 'website', 'recommended', 'google', 'maps', 'map', 'site',
    ];

    private const HEBREW_PREFIXES = ['ו', 'ה', 'ב', 'ל', 'מ', 'ש', 'כ'];

    private const SEVERE = ['invented', 'wrong_data', 'misattributed'];

    public function __construct(
        private LocationFactExtractor $extractor,
        private GeminiJsonClient $gemini,
    ) {}

    /**
     * Check; if the reviewer found issues, let Gemini fix exactly those facts once and check again.
     *
     * @return array{status: string, coverage: int, issues: int, repaired: int}
     */
    public function checkAndRepair(Location $location): array
    {
        $first = $this->check($location);
        $review = $location->refresh()->facts_review;
        $issues = (array) data_get($review, 'issues', []);
        foreach (array_filter((array) data_get($review, 'uncovered', []), [$this, 'hasData']) as $line) {
            $issues[] = ['fact' => null, 'kind' => 'missing', 'note' => 'Строка из текста не попала ни в один факт: '.$line];
        }
        if ($issues === []) {
            return $first + ['repaired' => 0];
        }

        $fix = $this->extractor->repair($location, $issues);
        $second = $this->check($location->refresh());

        $review = $location->refresh()->facts_review;
        $review['repaired'] = ['changed' => $fix['changed'], 'issues_before' => $issues];
        $location->forceFill(['facts_review' => $review])->save();

        return $second + ['repaired' => $fix['changed']];
    }

    /**
     * Coverage (code) + verifier (Gemini) → facts_status ok / warn / bad.
     *
     * @return array{status: string, coverage: int, issues: int}
     */
    public function check(Location $location): array
    {
        $location->load('facts.descriptions');
        $src = $this->extractor->sources($location);
        $facts = $this->factsPayload($location);
        [$coverage, $uncovered] = $this->coverage($src['text'], $facts);

        $issues = $facts === [] ? [] : $this->verify((string) ($location->heMeta()['title'] ?? ''), $src['material'], $facts, $uncovered);

        $dropped = (int) data_get($location->facts_review, 'dropped_links', 0);
        $kinds = array_column($issues, 'kind');
        $status = self::statusFor($facts !== [], $coverage, $kinds, $dropped, $uncovered);

        $location->forceFill([
            'facts_status' => $status,
            'facts_coverage' => $coverage,
            'facts_review' => [
                'coverage' => $coverage,
                'uncovered' => array_slice($uncovered, 0, 40),
                'issues' => $issues,
                'dropped_links' => $dropped,
                'checked_at' => now()->toIso8601String(),
            ],
        ])->save();

        return ['status' => $status, 'coverage' => $coverage, 'issues' => count($issues)];
    }

    /**
     * Low word coverage alone is not a problem when the reviewer found nothing: Gemini paraphrases.
     *
     * @param  list<string>  $kinds
     * @param  list<string>  $uncovered
     */
    public static function statusFor(bool $hasFacts, int $coverage, array $kinds, int $dropped, array $uncovered): string
    {
        $uncoveredData = array_filter($uncovered, [self::class, 'hasData']);

        return match (true) {
            ! $hasFacts, $coverage < 70, array_intersect($kinds, self::SEVERE) !== [] => 'bad',
            $kinds !== [], $dropped > 0, $uncoveredData !== [] => 'warn',
            default => 'ok',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factsPayload(Location $location): array
    {
        $out = [];
        foreach ($location->facts as $i => $fact) {
            $d = $fact->descriptions->first();
            $out[] = [
                'index' => $i + 1,
                'group' => $fact->group,
                'type' => $d?->type,
                'name' => $d?->name,
                'text' => $d?->text,
                'hours' => $d?->hours,
                'address' => $fact->address,
                'map_url' => $fact->map_url,
                'waze_url' => $fact->waze_url,
                'website' => $fact->website,
                'is_kosher' => $fact->is_kosher,
            ];
        }

        return $out;
    }

    /**
     * Share of informative source lines whose URLs and most words appear in the facts.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array{0: int, 1: list<string>}
     */
    private function coverage(string $text, array $facts): array
    {
        $corpus = mb_strtolower(implode("\n", array_map(
            fn ($f) => implode("\n", array_filter(array_map('strval', array_values($f)))),
            $facts
        )));
        preg_match_all('~https?://\S+~u', $corpus, $cm);
        $corpusUrls = array_flip(array_map([$this, 'urlKey'], $cm[0]));

        $considered = 0;
        $uncovered = [];
        foreach (array_unique(preg_split('/\r\n|\r|\n/u', $text) ?: []) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            preg_match_all('~https?://[^\s()|]+~u', $line, $m);
            $urls = array_values(array_filter($m[0], fn ($u) => ! collect($m[0])->contains(fn ($o) => $o !== $u && str_starts_with($o, $u))));
            $rest = mb_strtolower(preg_replace('~https?://[^\s()|]+~u', ' ', $line) ?? $line);
            preg_match_all('/\p{L}{3,}/u', $rest, $w);
            $tokens = array_values(array_filter($w[0], fn ($t) => ! $this->isLabel($t)));
            preg_match_all('/\d{1,2}[:.]\d{2}|\d{2,}/u', $rest, $n);
            $numbers = array_values(array_filter($n[0], fn ($x) => ! preg_match('/^(19|20)\d{2}$/', $x)));

            $isHeading = $urls === [] && $numbers === [] && count($tokens) <= 3;
            if ($isHeading || ($urls === [] && $tokens === [] && $numbers === []) || self::isLabelWithHours($line)) {
                continue;
            }
            $considered++;

            if ($urls !== []) {
                $covered = collect($urls)->every(fn ($u) => isset($corpusUrls[$this->urlKey($u)]));
            } else {
                $numbersOk = collect($numbers)->every(fn ($x) => str_contains($corpus, $x));
                $hits = collect($tokens)->filter(fn ($t) => $this->tokenIn($t, $corpus))->count();
                $wordsOk = $tokens === [] || $hits / count($tokens) >= 0.6;
                $covered = $numbers !== [] ? $numbersOk : $wordsOk;
            }

            if (! $covered) {
                $uncovered[] = mb_substr($line, 0, 300);
            }
        }

        $coverage = $considered === 0 ? 100 : (int) round(100 * ($considered - count($uncovered)) / $considered);

        return [$coverage, $uncovered];
    }

    /**
     * Comparable form of a link: host + path (+ Waze place/ll), without scheme, www, utm and trailing punctuation.
     */
    private function urlKey(string $url): string
    {
        $url = mb_strtolower(rtrim(html_entity_decode($url), ').,;|\\/'));
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $key = preg_replace('/^www\./', '', $parts['host']).rtrim($parts['path'] ?? '', '/\\');
        parse_str($parts['query'] ?? '', $q);
        foreach (['place', 'll', 'q', 'id'] as $p) {
            if (! empty($q[$p]) && is_string($q[$p])) {
                $key .= '?'.$p.'='.$q[$p];
                break;
            }
        }

        return $key;
    }

    /**
     * Uncovered line that carries data a route builder needs (a real link or hours not sitting under a label).
     */
    public static function hasData(string $line): bool
    {
        if (self::isLabelWithHours($line)) {
            return false;
        }

        return (bool) preg_match('~https?://|\b\d{1,2}:\d{2}\b~u', $line);
    }

    /**
     * Word row like "קישור לאתר: | 12:15-15:00" — a label with hours pasted into the website cell.
     */
    public static function isLabelWithHours(string $line): bool
    {
        if (preg_match('~https?://~u', $line)) {
            return false;
        }
        if (! preg_match('/\d{1,2}[:.]\d{2}/u', $line)) {
            return false;
        }
        $rest = mb_strtolower(preg_replace('/\d{1,2}[:.]\d{2}|\d+/u', ' ', $line) ?? $line);
        preg_match_all('/\p{L}{2,}/u', $rest, $w);
        if ($w[0] === []) {
            return true;
        }

        return collect($w[0])->every(fn ($t) => self::tokenIsLabel($t));
    }

    private static function tokenIsLabel(string $token): bool
    {
        $token = mb_strtolower($token);
        if (in_array($token, self::LABEL_WORDS, true)) {
            return true;
        }

        return in_array(mb_substr($token, 0, 1), self::HEBREW_PREFIXES, true)
            && in_array(mb_substr($token, 1), self::LABEL_WORDS, true);
    }

    private function isLabel(string $token): bool
    {
        if (in_array($token, self::LABEL_WORDS, true)) {
            return true;
        }

        return in_array(mb_substr($token, 0, 1), self::HEBREW_PREFIXES, true)
            && in_array(mb_substr($token, 1), self::LABEL_WORDS, true);
    }

    private function tokenIn(string $token, string $corpus): bool
    {
        if (str_contains($corpus, $token)) {
            return true;
        }
        $first = mb_substr($token, 0, 1);
        if (in_array($first, self::HEBREW_PREFIXES, true) && mb_strlen($token) > 3) {
            return str_contains($corpus, mb_substr($token, 1));
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<string>  $uncovered
     * @return list<array{fact: int|null, kind: string, note: string}>
     */
    private function verify(string $title, string $material, array $facts, array $uncovered): array
    {
        $groups = implode(', ', array_keys(LocationFact::GROUPS));
        $instruction = <<<PROMPT
You are a strict reviewer. A travel guide text about "{$title}" was split into facts for an automatic route builder.
Compare the FACTS with the SOURCE TEXT and report only real problems that would mislead a route builder or a traveller:
- invented: a fact, name, address, link, price or hour that is not in the text;
- wrong_data: an address, hour, day, price or link copied incorrectly;
- misattributed: information attached to the wrong place (e.g. a general sentence about several restaurants attached to one);
- missing: useful information from the text that is in no fact (parking, a place, hours, an important tip). The "uncovered lines" list is a hint from an automatic check; ignore lines that are only headings or labels;
- wrong_group: group clearly wrong (allowed groups: {$groups});
- other: anything else important.
Ignore wording, style, language, order and minor shortening. If everything is fine, return an empty list.
Write "note" in Russian, short and concrete (what is wrong and what is correct). "fact" is the fact index or null.
Reply with JSON only: {"issues":[{"fact":1,"kind":"missing","note":"..."}]}
PROMPT;

        $material .= "\n\n=== FACTS ===\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n\n=== UNCOVERED LINES (automatic hint) ===\n".($uncovered === [] ? '(none)' : implode("\n", $uncovered));

        $decoded = $this->gemini->ask($instruction, $material);

        $out = [];
        foreach ((array) ($decoded['issues'] ?? []) as $issue) {
            if (! is_array($issue) || trim((string) ($issue['note'] ?? '')) === '') {
                continue;
            }
            $kind = (string) ($issue['kind'] ?? 'other');
            $out[] = [
                'fact' => is_numeric($issue['fact'] ?? null) ? (int) $issue['fact'] : null,
                'kind' => in_array($kind, ['invented', 'wrong_data', 'misattributed', 'missing', 'wrong_group', 'other'], true) ? $kind : 'other',
                'note' => trim((string) $issue['note']),
            ];
        }

        return $out;
    }
}
