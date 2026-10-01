<?php

namespace App\Park\Console;

use App\Park\Actions\FillFromDocs;
use Illuminate\Console\Command;

/**
 * Пустые поля ТС на парковке — из их писем и документов (`FillFromDocs`). Решение владельца 01.10.2026: вписывать
 * сразу, только в пустые поля, с записью в истории дела; спорное — списком. Без `--apply` — только показать.
 */
class FillFromDocsCommand extends Command
{
    protected $signature = 'park:fill-from-docs {--apply : вписать, а не только показать} {--vehicle=* : только эти ТС}';

    protected $description = 'Вписать в пустые поля ТС найденное в письмах и документах';

    public function handle(FillFromDocs $fill): int
    {
        $apply = (bool) $this->option('apply');
        $filled = $fields = $disputed = 0;
        foreach ($fill->vehicles(array_map('intval', (array) $this->option('vehicle'))) as $vehicle) {
            $plan = $fill->plan($vehicle);
            if (! $plan['fill'] && ! $plan['disputed']) {
                continue;
            }
            $this->line("ТС {$vehicle->id} {$vehicle->titleWithYear()}");
            foreach ($plan['fill'] as $field => $item) {
                $this->line("  + {$field}: {$item['value']} — ".implode(', ', $item['sources']));
            }
            foreach ($plan['disputed'] as $d) {
                $this->line("  ? {$d['text']}");
            }
            if ($plan['fill']) {
                $filled++;
                $fields += count($apply ? $fill($vehicle, $plan) : $plan['fill']);
            }
            $disputed += count($plan['disputed']);
        }
        $this->info(($apply ? 'вписано' : 'впишется').": ТС {$filled}, полей {$fields}; спорно {$disputed}".($apply ? '' : '; с --apply впишет'));

        return self::SUCCESS;
    }
}
