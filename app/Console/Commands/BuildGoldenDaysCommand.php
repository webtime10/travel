<?php

namespace App\Console\Commands;

use App\Services\GoldenDayBuilder;
use Illuminate\Console\Command;

class BuildGoldenDaysCommand extends Command
{
    protected $signature = 'travel:build-golden-days
                            {--fresh : Truncate golden_days and rebuild}
                            {--min-stops=1 : Skip skeleton days with fewer places}';

    protected $description = 'Split route_skeleton_days into unique golden_days (block planning templates)';

    public function handle(GoldenDayBuilder $builder): int
    {
        $this->info('Building golden days from route skeletons…');
        $result = $builder->buildFromSkeletons([
            'fresh' => (bool) $this->option('fresh'),
            'min_stops' => (int) $this->option('min-stops'),
        ]);

        $this->info("Created: {$result['created']}");
        $this->info("Reused (same signature): {$result['reused']}");
        $this->info("Skipped (too few stops): {$result['skipped']}");
        $this->info("Stops written: {$result['stops']} (linked to locations: {$result['linked']})");

        return self::SUCCESS;
    }
}
