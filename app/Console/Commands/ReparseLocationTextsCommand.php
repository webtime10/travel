<?php

namespace App\Console\Commands;

use App\Services\LocationWordTextReparser;
use Illuminate\Console\Command;

class ReparseLocationTextsCommand extends Command
{
    protected $signature = 'travel:reparse-location-texts
                            {--file= : Path to locations_by_id.json}
                            {--only= : Comma-separated Python location ids (model), e.g. 44,455}
                            {--limit=20 : Max locations to process (0 = all)}
                            {--wipe-facts : Delete AI/split facts (default on)}
                            {--keep-facts : Keep existing facts}
                            {--keep-meta-ru : Keep meta_ru translation units}
                            {--no-backup : Do not write backup JSON}
                            {--dry-run : Show what would change, write nothing}';

    protected $description = 'Re-apply full Word plain text from export JSON onto existing locations (same model/id). Clears incomplete meta_ru; optional facts wipe. Does not touch attributes.';

    public function handle(LocationWordTextReparser $reparser): int
    {
        $onlyOpt = trim((string) $this->option('only'));
        $only = $onlyOpt !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $onlyOpt))))
            : [];

        $limit = (int) $this->option('limit');
        // Default wipe-facts = true unless --keep-facts
        $wipeFacts = ! $this->option('keep-facts');
        if ($this->option('wipe-facts')) {
            $wipeFacts = true;
        }

        $file = trim((string) $this->option('file'));
        $path = $file !== '' ? $file : $reparser->defaultExportPath();

        $this->info('Source: '.$path);
        $this->info('limit='.$limit.' wipe_facts='.($wipeFacts ? 'yes' : 'no')
            .' clear_meta_ru='.($this->option('keep-meta-ru') ? 'no' : 'yes')
            .' dry_run='.($this->option('dry-run') ? 'yes' : 'no'));

        try {
            $result = $reparser->run([
                'file' => $path,
                'only' => $only,
                'limit' => $limit,
                'wipe_facts' => $wipeFacts,
                'clear_meta_ru' => ! $this->option('keep-meta-ru'),
                'dry_run' => (bool) $this->option('dry-run'),
                'backup' => ! $this->option('no-backup'),
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Updated: {$result['updated']}");
        $this->info("Skipped (empty text): {$result['skipped']}");
        $this->info("Missing location (no model match): {$result['missing']}");
        $this->info("Facts wiped: {$result['facts_wiped']}");
        if ($result['backup_dir']) {
            $this->info('Backup: '.$result['backup_dir']);
        }
        foreach ($result['samples'] as $s) {
            $this->line("  sample location_id={$s['location_id']} model={$s['model']} chars={$s['chars']}");
        }

        if ($result['updated'] > 0 && ! $this->option('dry-run')) {
            $this->newLine();
            $this->comment('Next: re-index RAG if needed — php artisan travel:index-locations --pending');
            $this->comment('Full catalog: php artisan travel:reparse-location-texts --limit=0');
        }

        return self::SUCCESS;
    }
}
