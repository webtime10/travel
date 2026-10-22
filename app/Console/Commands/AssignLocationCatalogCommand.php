<?php

namespace App\Console\Commands;

use App\Services\LocationCatalogAssigner;
use Illuminate\Console\Command;

class AssignLocationCatalogCommand extends Command
{
    protected $signature = 'travel:assign-catalog';

    protected $description = 'Create manufacturers (countries), region categories and seed attributes; attach locations';

    public function handle(LocationCatalogAssigner $assigner): int
    {
        $result = $assigner->assign();
        $this->info('Locations: '.$result['assigned'].', countries: '.$result['countries'].', regions: '.$result['regions']);
        if ($result['unknown'] !== []) {
            $this->warn('Unrecognized:');
            foreach ($result['unknown'] as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }
}
