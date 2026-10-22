<?php

namespace App\Console\Commands;

use App\Models\RouteSkeleton;
use App\Models\RouteSkeletonDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRouteSkeletonsCommand extends Command
{
    protected $signature = 'travel:import-route-skeletons
                            {--file= : Path to route_skeletons.jsonl}
                            {--fresh : Truncate skeletons before import}
                            {--limit=0 : Max rows to import (0 = all)}';

    protected $description = 'Import brief route skeletons (from travel-python export) into route_skeletons tables';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: base_path('../travel-python/export_locations_json/route_skeletons.jsonl'));
        if (! is_file($path)) {
            // fallback: copy next to Laravel export folder
            $alt = base_path('export_locations_json/route_skeletons.jsonl');
            $path = is_file($alt) ? $alt : $path;
        }
        if (! is_file($path)) {
            $this->error('File not found: '.$path);
            $this->line('Сначала: python export_route_skeletons.py в travel-python');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Truncating route_skeleton_days + route_skeletons…');
            RouteSkeletonDay::query()->delete();
            RouteSkeleton::query()->delete();
        }

        $limit = max(0, (int) $this->option('limit'));
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            $this->error('Cannot open: '.$path);

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $n = 0;

        DB::connection()->disableQueryLog();

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $n++;
            if ($limit > 0 && $n > $limit) {
                break;
            }
            $row = json_decode($line, true);
            if (! is_array($row) || empty($row['source_route_id'])) {
                $skipped++;

                continue;
            }

            $days = is_array($row['days'] ?? null) ? $row['days'] : [];
            if ($days === []) {
                $skipped++;

                continue;
            }

            $attrs = [
                'title' => (string) ($row['title'] ?? ''),
                'start_date' => $row['start_date'] ?? null,
                'days_count' => (int) ($row['days_count'] ?? count($days)),
                'is_template' => (bool) ($row['is_template'] ?? false),
                'arrival_hub' => $this->clip((string) ($row['arrival_hub'] ?? ''), 120),
                'departure_hub' => $this->clip((string) ($row['departure_hub'] ?? ''), 120),
                'search_blob' => (string) ($row['search_blob'] ?? ''),
                'meta' => ['imported_at' => now()->toIso8601String()],
            ];

            $skeleton = RouteSkeleton::query()->updateOrCreate(
                ['source_route_id' => (int) $row['source_route_id']],
                $attrs
            );
            $wasRecently = $skeleton->wasRecentlyCreated;
            $skeleton->days()->delete();

            $insert = [];
            $now = now();
            foreach ($days as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $insert[] = [
                    'route_skeleton_id' => $skeleton->id,
                    'day_index' => (int) ($d['day_index'] ?? 0),
                    'title' => $this->clip((string) ($d['title'] ?? ''), 255),
                    'start_point' => $this->clip((string) ($d['start_point'] ?? ''), 255),
                    'end_point' => $this->clip((string) ($d['end_point'] ?? ''), 255),
                    'places' => json_encode(is_array($d['places'] ?? null) ? $d['places'] : [], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($insert !== []) {
                foreach (array_chunk($insert, 100) as $chunk) {
                    RouteSkeletonDay::query()->insert($chunk);
                }
            }

            if ($wasRecently) {
                $created++;
            } else {
                $updated++;
            }

            if ($n % 200 === 0) {
                $this->line("… {$n} lines (created={$created} updated={$updated})");
            }
        }
        fclose($fh);

        $this->info("Done. created={$created} updated={$updated} skipped={$skipped} total_in_db=".RouteSkeleton::count());

        return self::SUCCESS;
    }

    private function clip(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max);
    }
}
