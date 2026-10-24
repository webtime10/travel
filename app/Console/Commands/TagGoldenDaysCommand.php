<?php

namespace App\Console\Commands;

use App\Services\GoldenDayTagger;
use Illuminate\Console\Command;

class TagGoldenDaysCommand extends Command
{
    protected $signature = 'travel:tag-golden-days';

    protected $description = 'Auto-assign area/region/hub on golden_days and deactivate junk templates';

    public function handle(GoldenDayTagger $tagger): int
    {
        $this->info('Tagging golden days…');
        $r = $tagger->tagAll();
        $this->info("Tagged: {$r['tagged']}");
        $this->info("Junk deactivated: {$r['junk_off']}");
        $this->info("With area: {$r['with_area']}, category: {$r['with_category']}, hub: {$r['with_hub']}");

        return self::SUCCESS;
    }
}
