<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Location;
use App\Models\LocationFact;
use App\Models\LocationFactDescription;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocationFactExtractor
{
    public function __construct(private GeminiJsonClient $gemini) {}

    /**
     * Source documents used for facts: primary Word text plus an optional kosher variant.
     *
     * @return array{primary: array<string, mixed>, kosher: array<string, mixed>|null, material: string, text: string}
     */
    public function sources(Location $location): array
    {
        $meta = $location->heMeta();
        if ($meta === null) {
            throw new RuntimeException('Нет исходного JSON (meta)');
        }

        $documents = (array) ($meta['documents'] ?? []);
        $primary = collect($documents)->firstWhere('file_id', $meta['primary_file_id'] ?? null) ?? ($documents[0] ?? null);
        if (! $primary || trim((string) $primary['text']) === '') {
            throw new RuntimeException('Пустой текст Word');
        }
        $kosher = collect($documents)->first(fn ($d) => ! empty($d['is_kosher'])
            && ($d['file_id'] ?? null) !== ($primary['file_id'] ?? null)
            && trim((string) $d['text']) !== '');

        $text = (string) $primary['text'];
        $material = "=== MAIN TEXT ===\n".$text;
        if ($kosher) {
            $material .= "\n\n=== KOSHER VERSION ===\n".$kosher['text'];
            $text .= "\n".$kosher['text'];
        }

        return ['primary' => $primary, 'kosher' => $kosher, 'material' => $material, 'text' => $text];
    }

    /**
     * Split the location's source Word text into facts (replaces existing facts).
     *
     * @return array{count: int, dropped_links: int}
     */
    public function extract(Location $location): array
    {
        $he = Language::where('code', 'he')->first();
        if (! $he) {
            throw new RuntimeException('Language "he" not found');
        }

        $src = $this->sources($location);
        $title = (string) ($location->heMeta()['title'] ?? '');

        $parts = array_map(fn ($c) => "=== MAIN TEXT ===\n".$c, $this->chunks((string) $src['primary']['text']));
        if ($src['kosher']) {
            $parts[] = "=== KOSHER VERSION ===\n".$src['kosher']['text'];
        }
        $facts = [];
        foreach ($parts as $n => $part) {
            $instruction = $this->instruction($title);
            if (count($parts) > 1) {
                $instruction .= "\nThis is part ".($n + 1).' of '.count($parts).' of a long text.'
                    .($n === 0 ? '' : ' Do NOT create the "about" fact in this part.');
            }
            $decoded = $this->gemini->ask($instruction, $part);
            array_push($facts, ...array_values(array_filter((array) ($decoded['facts'] ?? []), 'is_array')));
        }

        return $this->persist($location, $facts, $src, $he);
    }

    /**
     * Fix only the facts named in checker issues (update / add / delete), then save the whole list again.
     *
     * @param  list<array{fact: int|null, kind: string, note: string}>  $issues
     * @return array{count: int, dropped_links: int, changed: int}
     */
    public function repair(Location $location, array $issues): array
    {
        $he = Language::where('code', 'he')->firstOrFail();
        $src = $this->sources($location);
        $location->load('facts.descriptions');

        $facts = [];
        foreach ($location->facts as $i => $fact) {
            $d = $fact->descriptions->firstWhere('language_id', $he->id) ?? $fact->descriptions->first();
            $facts[$i + 1] = [
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

        $title = (string) ($location->heMeta()['title'] ?? '');
        $instruction = <<<PROMPT
You fix a list of facts extracted from a travel guide text about "{$title}". A reviewer found the ISSUES below.
Fix ONLY these issues, using ONLY the source text. Copy addresses, URLs and hours exactly as written. Keep the source language.
Facts are referenced by "index". Return only the changes:
- "update": changed facts with their index and only the fields that change;
- "add": new facts (same fields as existing ones) for missing information;
- "delete": indexes of facts that are invented or duplicated.
Never drop real information from the text: if one fact mixes two places (e.g. two different map links), keep one place in it and "add" a separate fact for the other with its own link.
A link that is not the place's own map / Waze / official site (walking route, trail, tickets, timetable) belongs in "text" as "label: URL" — move it there instead of deleting it.
Reply with JSON only: {"update":[{"index":1,"hours":"..."}],"add":[],"delete":[]}
PROMPT;
        $material = $src['material']
            ."\n\n=== FACTS ===\n".json_encode(array_map(fn ($f, $i) => ['index' => $i] + $f, $facts, array_keys($facts)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\n=== ISSUES ===\n".json_encode($issues, JSON_UNESCAPED_UNICODE);

        $patch = $this->gemini->ask($instruction, $material);

        $changed = 0;
        foreach ((array) ($patch['update'] ?? []) as $u) {
            $idx = (int) ($u['index'] ?? 0);
            if (! is_array($u) || ! isset($facts[$idx])) {
                continue;
            }
            unset($u['index']);
            $facts[$idx] = array_merge($facts[$idx], array_intersect_key($u, $facts[$idx]));
            $changed++;
        }
        foreach ((array) ($patch['delete'] ?? []) as $idx) {
            if (isset($facts[(int) $idx])) {
                unset($facts[(int) $idx]);
                $changed++;
            }
        }
        foreach ((array) ($patch['add'] ?? []) as $a) {
            if (is_array($a)) {
                $facts[] = $a;
                $changed++;
            }
        }

        return $this->persist($location, array_values($facts), $src, $he) + ['changed' => $changed];
    }

    /**
     * Validate (links must appear in the source, generic duplicates merged) and replace the location's facts.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array{count: int, dropped_links: int}
     */
    private function persist(Location $location, array $facts, array $src, Language $he): array
    {
        $dropped = 0;
        $rows = [];
        $seenGeneric = [];
        foreach ($facts as $i => $f) {
            $name = trim((string) ($f['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $group = (string) ($f['group'] ?? 'other');
            if (! array_key_exists($group, LocationFact::GROUPS)) {
                $group = 'other';
            }
            if (in_array($group, ['tip', 'time', 'other'], true)) {
                $key = $group.'|'.mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($f['text'] ?? ''))) ?? '');
                if ($key !== $group.'|' && isset($seenGeneric[$key])) {
                    continue;
                }
                $seenGeneric[$key] = true;
            }
            $links = [];
            foreach (['map_url', 'waze_url', 'website', 'address'] as $field) {
                $value = trim((string) ($f[$field] ?? ''));
                $parts = $field === 'address' ? [$value] : (preg_split('/\s+/u', $value) ?: []);
                $kept = [];
                foreach (array_filter($parts, fn ($p) => $p !== '') as $part) {
                    if (self::appearsIn($part, $src['text'])) {
                        $kept[] = $part;
                    } else {
                        $dropped++;
                    }
                }
                $links[$field] = $kept !== [] ? implode(' ', $kept) : null;
            }
            $text = preg_replace_callback('~https?://[^\s()|]+~u', function ($m) use ($src, &$dropped) {
                if (self::appearsIn($m[0], $src['text'])) {
                    return $m[0];
                }
                $dropped++;

                return '';
            }, trim((string) ($f['text'] ?? ''))) ?? '';
            $isKosher = (bool) ($f['is_kosher'] ?? false);
            $rows[] = [
                'fact' => $links + [
                    'group' => $group,
                    'is_kosher' => $isKosher,
                    'source_file_id' => $isKosher && $src['kosher'] ? $src['kosher']['file_id'] : ($src['primary']['file_id'] ?? null),
                    'sort_order' => $i,
                ],
                'desc' => [
                    'type' => mb_substr(trim((string) ($f['type'] ?? '')), 0, 255) ?: null,
                    'name' => mb_substr($name, 0, 255),
                    'text' => trim($text) ?: null,
                    'hours' => trim((string) ($f['hours'] ?? '')) ?: null,
                ],
            ];
        }

        DB::transaction(function () use ($location, $rows, $he, $dropped) {
            $location->facts()->delete();
            foreach ($rows as $row) {
                $fact = LocationFact::create($row['fact'] + ['location_id' => $location->id]);
                LocationFactDescription::create($row['desc'] + [
                    'location_fact_id' => $fact->id,
                    'language_id' => $he->id,
                ]);
            }
            $location->forceFill([
                'facts_split_at' => now(),
                'facts_status' => null,
                'facts_coverage' => null,
                'facts_review' => ['dropped_links' => $dropped],
            ])->save();
        });

        return ['count' => count($rows), 'dropped_links' => $dropped];
    }

    /**
     * Long Word texts are split on line boundaries so Gemini does not drop details at the end.
     *
     * @return list<string>
     */
    private function chunks(string $text, int $max = 8000): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }
        $isHeading = fn (string $line) => mb_strlen(trim($line)) <= 80
            && ! str_contains($line, ':')
            && ! preg_match('~https?://|\d{1,2}:\d{2}~u', $line);

        $chunks = [];
        $buf = '';
        foreach (preg_split('/\r\n|\r|\n/u', $text) ?: [] as $line) {
            $len = mb_strlen($buf);
            if ($buf !== '' && (($len >= $max && $isHeading($line)) || $len >= (int) ($max * 1.5))) {
                $chunks[] = $buf;
                $buf = '';
            }
            $buf = $buf === '' ? $line : $buf."\n".$line;
        }
        if (trim($buf) !== '') {
            $chunks[] = $buf;
        }

        return $chunks;
    }

    public static function appearsIn(string $value, string $source): bool
    {
        $norm = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return str_contains($norm($source), $norm($value));
    }

    private function instruction(string $title): string
    {
        $groups = implode(', ', array_keys(LocationFact::GROUPS));

        return <<<PROMPT
You split a travel guide text about one location ("{$title}") into separate facts for a route builder.
Each fact is one concrete thing: a parking spot, an attraction, a viewpoint, a museum, an activity, a restaurant, a place to sleep, visit duration or opening hours, a practical tip.

Rules:
- Use ONLY information present in the text. Never invent places, addresses, links, prices or hours.
- Copy addresses, URLs and opening hours exactly as written (character for character). If a field is absent, use "".
- Write "type", "name", "text" and "hours" in the same language as the source text (usually Hebrew).
- "group" must be one of: {$groups}.
  about = what this place is (from the introduction/description sentences); parking = where to park; attraction = sights, museums, viewpoints, walks; activity = paid/organized activities (canyoning, boats, tours);
  food = restaurants, cafes, food shops; overnight = hotels, camping;
  transport = how to get from A to B by train, bus, ferry, cable car, shuttle or taxi;
  time = visit duration or general opening hours;
  tip = practical advice or local customs; other = anything else useful.
- "type" is your own short label describing what it is (e.g. "מסעדה איטלקית", "נקודת תצפית", "חניה בתשלום").
- Put the "Waze" link into waze_url, the Google Maps link of the place itself into map_url, the official site into website.
- Any other link (walking route, trail, parking map, tickets, timetable, video) goes into "text" as "label: URL" with the label from the source. Never drop a link.
- Local traditions, typical dishes, traditional meals and customs (e.g. "Pranzo con i Pastori", a local market day, a dress code) are separate facts with group "tip", even if they appear inside a restaurants section.
- Transport: one fact per connection "from A to B". The name must show the direction unambiguously in the source language: Hebrew "מ-A ל-B", English "From A to B" (never just "A B"). Put the line/bus/train number and how to travel into "text", ALL departure times exactly as written into "hours", ticket/info links into "website" (several links separated by a space), map links into map_url. Never drop departure times.
- A sentence that repeats many times in the text (boilerplate, e.g. "check the timetable before travelling") becomes ONE fact, not one per occurrence.
- A general statement about several places (e.g. "you can enjoy this meal in the recommended restaurants") must not be attached to only one of them.
- Start with exactly one "about" fact: name = the place, text = a factual summary built only from the introduction/description sentences (keep their content, add nothing). Greetings like "we will visit" may be dropped.
- Coverage check before answering: go through the text line by line. Every informative sentence must end up in some fact (as its name, text, address, link or hours). Only section headings (e.g. "מסעדות מומלצות", "אטרקציות מרכזיות") and pure link labels may be skipped.
- If a "KOSHER VERSION" section exists, extract from it ONLY food facts that are not already in the main text, and set "is_kosher": true. All other facts have "is_kosher": false.
- Keep the order of the text.

Reply with a single JSON object only:
{"facts":[{"group":"","type":"","name":"","text":"","address":"","map_url":"","waze_url":"","website":"","hours":"","is_kosher":false}]}
PROMPT;
    }
}
