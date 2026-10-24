<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Location;
use App\Models\LocationDescription;
use RuntimeException;
use Throwable;

class LocationRuTranslator
{
    private const CHUNK_CHARS = 1500;

    public function __construct(private GeminiTranslator $gemini) {}

    public static function looksHebrew(string $text): bool
    {
        return (bool) preg_match('/\p{Hebrew}/u', $text);
    }

    /**
     * Translate only the location title/name into RU description (list column).
     *
     * @return array{translated: bool, name: string, skipped: bool}
     */
    public function translateName(Location $location): array
    {
        $he = Language::where('code', 'he')->first();
        $ru = Language::where('code', 'ru')->first();
        if (! $ru) {
            throw new RuntimeException('Нет языка ru');
        }

        $location->loadMissing('descriptions');
        $heName = '';
        if ($he) {
            $heName = trim((string) ($location->descriptions->firstWhere('language_id', $he->id)?->name ?? ''));
        }
        if ($heName === '') {
            $heName = trim((string) ($location->heMeta()['title'] ?? ''));
        }
        if ($heName === '') {
            $heName = trim((string) ($location->model ?? '')) ?: ('#'.$location->id);
        }

        $ruDesc = $location->descriptions->firstWhere('language_id', $ru->id);
        $ruName = trim((string) ($ruDesc?->name ?? ''));
        if ($ruName !== '' && ! self::looksHebrew($ruName)) {
            return ['translated' => false, 'name' => $ruName, 'skipped' => true];
        }

        $map = $this->gemini->translateMap(['title' => $heName]);
        $name = trim((string) ($map['title'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Gemini не вернул русское имя');
        }

        LocationDescription::updateOrCreate(
            ['location_id' => $location->id, 'language_id' => $ru->id],
            [
                'name' => mb_substr($name, 0, 255),
                'description' => $ruDesc?->description,
            ]
        );

        $ruMeta = $location->ruMeta() ?? [];
        if (is_array($ruMeta)) {
            $ruMeta['title'] = $name;
            $units = is_array($ruMeta['units'] ?? null) ? $ruMeta['units'] : [];
            $units['title'] = $name;
            $ruMeta['units'] = $units;
            $location->meta_ru = $ruMeta;
            $location->save();
        }

        return ['translated' => true, 'name' => $name, 'skipped' => false];
    }

    /**
     * Keep calling translateBatch until units are done (or max rounds).
     *
     * @return array{done: bool, rounds: int, remaining: int, total: int}
     */
    public function translateAllContent(Location $location, int $batchLimit = 12, int $maxRounds = 40): array
    {
        $rounds = 0;
        $remaining = 0;
        $total = 0;
        $done = false;
        while ($rounds < $maxRounds) {
            $rounds++;
            $result = $this->translateBatch($location->refresh(), $batchLimit);
            $remaining = (int) $result['remaining'];
            $total = (int) $result['total'];
            $done = (bool) $result['done'];
            if ($done || $remaining === 0) {
                break;
            }
            if ((int) $result['translated'] === 0) {
                break;
            }
        }

        return [
            'done' => $done || $remaining === 0,
            'rounds' => $rounds,
            'remaining' => $remaining,
            'total' => $total,
        ];
    }

    public function needsNameRu(Location $location): bool
    {
        $ru = Language::where('code', 'ru')->first();
        if (! $ru) {
            return false;
        }
        $location->loadMissing('descriptions');
        $ruName = trim((string) ($location->descriptions->firstWhere('language_id', $ru->id)?->name ?? ''));

        return $ruName === '' || self::looksHebrew($ruName);
    }

    public function needsContentRu(Location $location): bool
    {
        $heMeta = $location->heMeta();
        if ($heMeta === null) {
            return $this->needsNameRu($location);
        }
        $units = $this->units($heMeta);
        if ($units === []) {
            return $this->needsNameRu($location);
        }
        $ruMeta = $location->ruMeta();
        $done = is_array($ruMeta['units'] ?? null) ? $ruMeta['units'] : [];
        $done = array_intersect_key($done, $units);
        if (count($done) < count($units)) {
            return true;
        }
        foreach ($done as $text) {
            if (self::looksHebrew((string) $text)) {
                return true;
            }
        }

        return $this->needsNameRu($location);
    }

    /**
     * One Gemini batch: translates up to $limit pending units into meta_ru and syncs the RU description row.
     *
     * @return array{translated: int, remaining: int, total: int, already: int, done: bool, warning?: string}
     */
    public function translateBatch(Location $location, int $limit = 12): array
    {
        $heMeta = $location->heMeta();
        if ($heMeta === null) {
            throw new RuntimeException('Нет исходного JSON (meta)');
        }

        $units = $this->units($heMeta);
        $ruMeta = $location->ruMeta();
        $done = is_array($ruMeta['units'] ?? null) ? $ruMeta['units'] : [];
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
                    } catch (Throwable $e) {
                        $lastError = $e->getMessage();
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

        $location->meta_ru = $this->buildRuMeta($heMeta, $units, $done);
        $location->save();
        $this->syncRuDescription($location, $heMeta, $units, $done);

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
     * Ordered translatable units: title, working hours, then each document text in line-aligned chunks.
     *
     * @param  array<string, mixed>  $heMeta
     * @return array<string, string>
     */
    public function units(array $heMeta): array
    {
        $units = [];
        $title = trim((string) ($heMeta['title'] ?? ''));
        if ($title !== '') {
            $units['title'] = $title;
        }
        $hours = trim((string) ($heMeta['working_hours'] ?? ''));
        if ($hours !== '') {
            $units['working_hours'] = $hours;
        }
        foreach ((array) ($heMeta['documents'] ?? []) as $d => $doc) {
            foreach ($this->chunks((string) ($doc['text'] ?? '')) as $j => $chunk) {
                $units['d'.$d.'_'.$j] = $chunk;
            }
        }

        return $units;
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $chunks = [];
        $buf = '';
        foreach (preg_split('/\r\n|\r|\n/u', $text) ?: [] as $line) {
            while (mb_strlen($line) > self::CHUNK_CHARS) {
                if (trim($buf) !== '') {
                    $chunks[] = $buf;
                }
                $buf = '';
                $chunks[] = mb_substr($line, 0, self::CHUNK_CHARS);
                $line = mb_substr($line, self::CHUNK_CHARS);
            }
            $candidate = $buf === '' ? $line : $buf."\n".$line;
            if (mb_strlen($candidate) > self::CHUNK_CHARS && trim($buf) !== '') {
                $chunks[] = $buf;
                $buf = $line;
            } else {
                $buf = $candidate;
            }
        }
        if (trim($buf) !== '') {
            $chunks[] = $buf;
        }

        return array_values(array_filter($chunks, fn ($c) => trim($c) !== ''));
    }

    /**
     * @param  array<string, mixed>  $heMeta
     * @param  array<string, string>  $units
     * @param  array<string, string>  $done
     * @return array<string, mixed>
     */
    private function buildRuMeta(array $heMeta, array $units, array $done): array
    {
        $documents = [];
        foreach ((array) ($heMeta['documents'] ?? []) as $d => $doc) {
            $doc['text'] = $this->documentText($d, $units, $done);
            $documents[] = $doc;
        }

        return [
            'format' => 'location_v1',
            'language' => 'ru',
            'location_id' => $heMeta['location_id'] ?? null,
            'title' => $done['title'] ?? '',
            'country' => $heMeta['country'] ?? null,
            'region' => $heMeta['region'] ?? null,
            'working_hours' => $done['working_hours'] ?? '',
            'primary_file_id' => $heMeta['primary_file_id'] ?? null,
            'documents' => $documents,
            'units' => $done,
            'stats' => ['total' => count($units), 'translated' => count($done)],
        ];
    }

    private function documentText(int $d, array $units, array $done): string
    {
        $parts = [];
        foreach ($units as $key => $_) {
            if (str_starts_with($key, 'd'.$d.'_') && isset($done[$key])) {
                $parts[] = $done[$key];
            }
        }

        return implode("\n", $parts);
    }

    private function syncRuDescription(Location $location, array $heMeta, array $units, array $done): void
    {
        $ru = Language::where('code', 'ru')->first();
        $name = trim((string) ($done['title'] ?? ''));
        if (! $ru || $name === '') {
            return;
        }

        $primaryIndex = 0;
        foreach ((array) ($heMeta['documents'] ?? []) as $d => $doc) {
            if (($doc['file_id'] ?? null) === ($heMeta['primary_file_id'] ?? null)) {
                $primaryIndex = (int) $d;
                break;
            }
        }

        LocationDescription::updateOrCreate(
            ['location_id' => $location->id, 'language_id' => $ru->id],
            ['name' => mb_substr($name, 0, 255), 'description' => $this->documentText($primaryIndex, $units, $done)]
        );
    }
}
