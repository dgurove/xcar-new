<?php

namespace App\Cars\Console;

use App\Cars\Duplicates;
use App\Offers\Actions\MergeOffers;
use App\Park\Actions\MergeVehicles;
use Illuminate\Console\Command;

/**
 * Двойники по номеру убытка и VIN (05.10.2026, владелец: «нигде нельзя создавать дубликаты») — ТС парковки, потом
 * предложения. Без `--apply` — только список, что куда уедет.
 */
class MergeDuplicatesCommand extends Command
{
    protected $signature = 'dups:merge {--apply : объединить, а не только показать}';

    protected $description = 'Объединить ТС и предложения с одним номером убытка или VIN';

    public function handle(): int
    {
        if ($this->option('apply')) {
            $n = Duplicates::mergeAll(fn ($line) => $this->line($line));
            $this->info("объединено {$n}");

            return self::SUCCESS;
        }
        $n = 0;
        foreach (Duplicates::vehicles() as $group) {
            $keep = MergeVehicles::pick($group);
            $group->reject(fn ($v) => $v->is($keep))->each(function ($v) use ($keep, &$n) {
                $this->line(Duplicates::line('vehicles', $keep, $v));
                $n++;
            });
        }
        foreach (Duplicates::offers() as $group) {
            $keep = MergeOffers::pick($group);
            $group->reject(fn ($o) => $o->is($keep))->each(function ($o) use ($keep, &$n) {
                $this->line(Duplicates::line('offers', $keep, $o));
                $n++;
            });
        }
        $this->info("объединится {$n}; с --apply объединит");

        return self::SUCCESS;
    }
}
