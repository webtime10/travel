<?php

namespace App\Console\Commands;

use App\Services\NewShortRouteDocImporter;
use Illuminate\Console\Command;

class ImportNewShortRoutesCommand extends Command
{
    protected $signature = 'travel:import-new-short-routes
        {--limit=30 : Max docx files}
        {--all-years : Do not filter by file mtime >= 2023}
        {--meta-only : Filename only, skip Word body parse}';

    protected $description = 'Import New Short routes from travel.loc/doc/*.docx';

    public function handle(NewShortRouteDocImporter $importer): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $since2023 = ! $this->option('all-years');
        $parseBody = ! $this->option('meta-only');

        $this->info('Scanning '.$importer->docDir().' limit='.$limit);

        $result = $importer->import($limit, $since2023, $parseBody);
        $this->info('scanned='.$result['scanned'].' upserted='.$result['upserted'].' skipped='.$result['skipped']);
        foreach ($result['errors'] as $err) {
            $this->warn($err);
        }

        return self::SUCCESS;
    }
}
