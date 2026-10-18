<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Location;
use App\Models\LocationDescription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLocationsCommand extends Command
{
    protected $signature = 'travel:import-locations
                            {--file= : Path to locations_by_id.json (default: export_locations_json/locations_by_id.json)}
                            {--only= : Comma-separated Python location ids, e.g. 43,44}';

    protected $description = 'Import travel-python locations (title + Word text) into locations / location_descriptions (Hebrew source, RU left empty)';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: base_path('export_locations_json/locations_by_id.json'));
        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            $this->error('Invalid JSON: '.$path);

            return self::FAILURE;
        }

        $he = Language::where('code', 'he')->first();
        if (! $he) {
            $this->error('Language "he" not found');

            return self::FAILURE;
        }

        $onlyOpt = trim((string) $this->option('only'));
        $only = $onlyOpt !== '' ? array_map('intval', explode(',', $onlyOpt)) : [];

        $created = 0;
        $updated = 0;
        $ruReset = 0;
        $bar = $this->output->createProgressBar(count($data));

        foreach ($data as $row) {
            $bar->advance();
            $sourceId = (int) ($row['location_id'] ?? 0);
            if ($sourceId <= 0 || ($only !== [] && ! in_array($sourceId, $only, true))) {
                continue;
            }

            $documents = array_values(array_map(fn ($d) => [
                'file_id' => $d['file_id'] ?? null,
                'lang' => $d['lang'] ?? null,
                'is_short' => (bool) ($d['is_short'] ?? false),
                'is_kosher' => (bool) ($d['is_kosher'] ?? false),
                'season' => $d['season'] ?? null,
                'source_file' => $d['source_file'] ?? null,
                'text' => (string) ($d['text'] ?? ''),
            ], (array) ($row['documents'] ?? [])));

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
            ];

            DB::transaction(function () use ($row, $sourceId, $meta, $primary, $he, &$created, &$updated, &$ruReset) {
                $location = Location::firstOrNew(['model' => (string) $sourceId]);
                $location->exists ? $updated++ : $created++;

                if ($location->exists && $location->meta_ru !== null && $this->sourceTexts($location->meta) !== $this->sourceTexts($meta)) {
                    $location->meta_ru = null;
                    $location->descriptions()->where('language_id', '!=', $he->id)->delete();
                    $ruReset++;
                }
                $location->fill([
                    'lat' => is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null,
                    'lng' => is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : null,
                    'status' => true,
                    'meta' => $meta,
                ]);
                $location->save();

                $name = trim((string) ($row['title'] ?? '')) ?: ('#'.$sourceId);
                LocationDescription::updateOrCreate(
                    ['location_id' => $location->id, 'language_id' => $he->id],
                    ['name' => mb_substr($name, 0, 255), 'description' => $primary['text'] ?? null]
                );
            });
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Created: {$created}, updated: {$updated}, RU reset (source text changed): {$ruReset}");

        return self::SUCCESS;
    }

    /**
     * Fields the RU translation is derived from.
     */
    private function sourceTexts(?array $meta): array
    {
        return [
            $meta['title'] ?? null,
            $meta['working_hours'] ?? null,
            array_map(fn ($d) => $d['text'] ?? '', (array) ($meta['documents'] ?? [])),
        ];
    }

    /**
     * Prefer Hebrew, full, standard (non-kosher), no season.
     *
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, mixed>|null
     */
    private function primaryDocument(array $documents): ?array
    {
        $withText = array_values(array_filter($documents, fn ($d) => trim($d['text']) !== ''));
        if ($withText === []) {
            return $documents[0] ?? null;
        }

        usort($withText, fn ($a, $b) => $this->rank($a) <=> $this->rank($b));

        return $withText[0];
    }

    private function rank(array $d): array
    {
        return [
            $d['lang'] === 'he' ? 0 : 1,
            $d['is_short'] ? 1 : 0,
            $d['is_kosher'] ? 1 : 0,
            empty($d['season']) ? 0 : 1,
        ];
    }
}
