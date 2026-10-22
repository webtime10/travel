<?php

namespace App\Console\Commands;

use App\Services\BatchRefineMetricsRunner;
use Illuminate\Console\Command;

class BatchRefineMetricsCommand extends Command
{
    protected $signature = 'travel:batch-refine-metrics
                            {--limit=80 : Сколько каркасов прогнать}
                            {--min-days=5 : Мин. дней в каркасе}
                            {--max-days=16 : Макс. дней в каркасе}
                            {--out= : JSON-файл (по умолчанию storage/app/batch_refine_*.json)}';

    protected $description = 'Батч pack→refine по short routes: метрики пустых/тонких/нормальных дней (без Gemini)';

    public function handle(BatchRefineMetricsRunner $runner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $minDays = max(2, (int) $this->option('min-days'));
        $maxDays = max($minDays, (int) $this->option('max-days'));

        $this->info("Батч refine: limit={$limit}, days={$minDays}–{$maxDays}…");
        $result = $runner->run($limit, $minDays, $maxDays);
        $s = $result['summary'];

        $this->newLine();
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Маршрутов OK / fail', ($s['ok_pack'] ?? 0).' / '.($s['failed'] ?? 0)],
                ['Дней всего', (string) ($s['days_total'] ?? 0)],
                ['Пустой stay %', ($s['pct_empty_stay'] ?? 0).'% ('.$s['empty_stay'].')'],
                ['Пустой transfer %', ($s['pct_empty_transfer'] ?? 0).'% ('.$s['empty_transfer'].'/'.$s['transfer_total'].')'],
                ['Transfer с активностью %', ($s['pct_transfer_filled'] ?? 0).'%'],
                ['Тонкий короткий день %', ($s['pct_thin_short'] ?? 0).'% ('.$s['thin_short'].')'],
                ['Норма / длинный hiking %', ($s['pct_normal_or_long'] ?? 0).'%'],
                ['Мульти-активность %', ($s['pct_multi_activity'] ?? 0).'% ('.$s['normal_multi'].')'],
                ['Перегруз', (string) ($s['overloaded'] ?? 0)],
                ['Не-маршрут POI (дни)', (string) ($s['not_route_poi_days'] ?? 0)],
                ['Чужой хаб (дни)', (string) ($s['hub_mismatch_days'] ?? 0)],
                ['Маршруты с дублями', (string) ($s['duplicate_place_routes'] ?? 0)],
                ['Validator OK маршрутов', (string) ($s['validator_ok_routes'] ?? 0)],
                ['Validator C/Maj/Min', ($s['validator_critical'] ?? 0).' / '.($s['validator_major'] ?? 0).' / '.($s['validator_minor'] ?? 0)],
                ['Время', ($result['elapsed_ms'] ?? 0).' мс'],
            ]
        );

        $out = (string) ($this->option('out') ?: '');
        if ($out === '') {
            $out = storage_path('app/batch_refine_'.date('Ymd_His').'.json');
        }
        file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('JSON: '.$out);

        $fails = array_values(array_filter($result['runs'], fn ($r) => ! ($r['ok'] ?? false)));
        if ($fails !== []) {
            $this->warn('Провалы (до 8):');
            foreach (array_slice($fails, 0, 8) as $f) {
                $this->line('  #'.($f['source_route_id'] ?? '?').' '.($f['error'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
