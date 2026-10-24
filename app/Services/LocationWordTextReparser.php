<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Location;
use App\Models\LocationDescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Re-apply full Word plain text from export JSON onto existing locations (match by model = Python location_id).
 * Keeps ids / coords / catalog / attributes. Replaces meta + HE description; clears incomplete meta_ru; optional facts wipe.
 */
class LocationWordTextReparser
{
    /**
     * @param  array{
     *   file?: string,
     *   only?: list<int>,
     *   limit?: int,
     *   wipe_facts?: bool,
     *   clear_meta_ru?: bool,
     *   dry_run?: bool,
     *   backup?: bool
     * }  $options
     * @return array{
     *   updated: int,
     *   skipped: int,
     *   missing: int,
     *   facts_wiped: int,
     *   backup_dir: ?string,
     *   samples: list<array{location_id: int, model: string, chars: int}>
     * }
     */
    public function run(array $options = []): array
    {
        $path = (string) ($options['file'] ?? $this->defaultExportPath());
        if (! is_file($path)) {
            throw new \RuntimeException('Export JSON not found: '.$path);
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            throw new \RuntimeException('Invalid JSON: '.$path);
        }

        $rows = array_values(array_filter(
            array_map(fn ($row) => is_array($row) ? $row : null, $raw),
            fn ($row) => $row !== null
        ));

        $only = array_values(array_filter(array_map('intval', (array) ($options['only'] ?? []))));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $wipeFacts = (bool) ($options['wipe_facts'] ?? true);
        $clearMetaRu = (bool) ($options['clear_meta_ru'] ?? true);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $doBackup = (bool) ($options['backup'] ?? true);

        $he = Language::where('code', 'he')->first();
        if (! $he) {
            throw new \RuntimeException('Language "he" not found');
        }

        $backupDir = null;
        if ($doBackup && ! $dryRun) {
            $backupDir = storage_path('app/location_reparse_backup/'.date('Y-m-d_His'));
            File::ensureDirectoryExists($backupDir);
        }

        $updated = 0;
        $skipped = 0;
        $missing = 0;
        $factsWiped = 0;
        $samples = [];
        $processed = 0;

        foreach ($rows as $row) {
            $sourceId = (int) ($row['location_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }
            if ($only !== [] && ! in_array($sourceId, $only, true)) {
                continue;
            }
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $location = Location::query()->where('model', (string) $sourceId)->first();
            if (! $location) {
                $missing++;
                $processed++;

                continue;
            }

            $documents = $this->normalizeDocuments((array) ($row['documents'] ?? []));
            $fullText = $this->composeFullText($documents);
            if ($fullText === '') {
                $skipped++;
                $processed++;

                continue;
            }

            $primary = $this->primaryDocument($documents);
            $meta = [
                'format' => 'location_v1',
                'location_id' => $sourceId,
                'title' => (string) ($row['title'] ?? ''),
                'country' => $row['country'] ?? null,
                'region' => $row['region'] ?? null,
                'working_hours' => (string) ($row['working_hours'] ?? ''),
                'source_lang' => $primary['lang'] ?? null,
                'primary_file_id' => $primary['file_id'] ?? null,
                'documents' => $documents,
                'reparsed_at' => now()->toIso8601String(),
                'reparse_mode' => 'full_plain_text',
            ];

            if (! $dryRun) {
                if ($backupDir) {
                    $this->backupLocation($backupDir, $location, $he->id);
                }

                DB::transaction(function () use (
                    $location,
                    $meta,
                    $fullText,
                    $row,
                    $he,
                    $clearMetaRu,
                    $wipeFacts,
                    &$factsWiped
                ) {
                    $location->refresh();
                    $location->meta = $meta;
                    if ($clearMetaRu) {
                        $location->meta_ru = null;
                    }
                    if ($wipeFacts) {
                        $n = $location->facts()->count();
                        $location->facts()->delete();
                        $factsWiped += $n;
                        $location->facts_status = null;
                        $location->facts_coverage = null;
                        $location->facts_review = null;
                        $location->facts_split_at = null;
                    }
                    $location->save();

                    $name = trim((string) ($row['title'] ?? '')) ?: ('#'.($row['location_id'] ?? $location->id));
                    LocationDescription::updateOrCreate(
                        ['location_id' => $location->id, 'language_id' => $he->id],
                        [
                            'name' => mb_substr($name, 0, 255),
                            'description' => $fullText,
                        ]
                    );
                });
            }

            $updated++;
            $processed++;
            if (count($samples) < 5) {
                $samples[] = [
                    'location_id' => $location->id,
                    'model' => (string) $sourceId,
                    'chars' => mb_strlen($fullText),
                ];
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'missing' => $missing,
            'facts_wiped' => $factsWiped,
            'backup_dir' => $backupDir,
            'samples' => $samples,
        ];
    }

    public function defaultExportPath(): string
    {
        $candidates = [
            base_path('export_locations_json/locations_by_id.json'),
            dirname(base_path()).DIRECTORY_SEPARATOR.'travel-python'.DIRECTORY_SEPARATOR.'export_locations_json'.DIRECTORY_SEPARATOR.'locations_by_id.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function normalizeDocuments(array $documents): array
    {
        $out = [];
        foreach ($documents as $d) {
            if (! is_array($d)) {
                continue;
            }
            $out[] = [
                'file_id' => $d['file_id'] ?? null,
                'lang' => $d['lang'] ?? null,
                'is_short' => (bool) ($d['is_short'] ?? false),
                'is_kosher' => (bool) ($d['is_kosher'] ?? false),
                'season' => $d['season'] ?? null,
                'source_file' => $d['source_file'] ?? null,
                'text' => (string) ($d['text'] ?? ''),
                'text_error' => $d['text_error'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Prefer full (non-short) docs; Hebrew first; then append remaining with separators.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    private function composeFullText(array $documents): string
    {
        $withText = array_values(array_filter(
            $documents,
            fn ($d) => trim((string) ($d['text'] ?? '')) !== ''
        ));
        if ($withText === []) {
            return '';
        }

        usort($withText, function ($a, $b) {
            return $this->docRank($a) <=> $this->docRank($b);
        });

        $parts = [];
        foreach ($withText as $doc) {
            $text = trim((string) $doc['text']);
            $labelBits = array_filter([
                $doc['lang'] ?? null,
                ! empty($doc['is_short']) ? 'short' : 'full',
                ! empty($doc['is_kosher']) ? 'kosher' : null,
                $doc['season'] ?? null,
            ]);
            $header = $labelBits !== [] ? '['.implode(' · ', $labelBits)."]\n" : '';
            $parts[] = $header.$text;
        }

        return trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n\n---\n\n", $parts)) ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, mixed>|null
     */
    private function primaryDocument(array $documents): ?array
    {
        $withText = array_values(array_filter(
            $documents,
            fn ($d) => trim((string) ($d['text'] ?? '')) !== ''
        ));
        if ($withText === []) {
            return $documents[0] ?? null;
        }
        usort($withText, fn ($a, $b) => $this->docRank($a) <=> $this->docRank($b));

        return $withText[0];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function docRank(array $d): array
    {
        return [
            ($d['lang'] ?? '') === 'he' ? 0 : 1,
            ! empty($d['is_short']) ? 1 : 0,
            ! empty($d['is_kosher']) ? 1 : 0,
            empty($d['season']) ? 0 : 1,
        ];
    }

    private function backupLocation(string $dir, Location $location, int $heLangId): void
    {
        $heDesc = $location->descriptions()->where('language_id', $heLangId)->first();
        $payload = [
            'location_id' => $location->id,
            'model' => $location->model,
            'meta' => $location->meta,
            'meta_ru' => $location->meta_ru,
            'facts_status' => $location->facts_status,
            'facts_coverage' => $location->facts_coverage,
            'he_description' => $heDesc?->description,
            'he_name' => $heDesc?->name,
            'facts_count' => $location->facts()->count(),
        ];
        File::put(
            $dir.DIRECTORY_SEPARATOR.$location->id.'.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
