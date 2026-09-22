<?php

namespace App\Park\Console;

use App\Park\Actions\StoreByLetters;
use Illuminate\Console\Command;

/**
 * Разовый прогон по хвосту: цепочки, про которые мы уже написали вендору «приняли», а ТС в системе нет.
 * Без `--apply` только показывает, что будет заведено и что пропущено и почему. Дальше это делает само
 * (`Mail\OnMessage` при письме, `park:tick` следом), команда нужна, чтобы посмотреть глазами перед выкладкой.
 */
class StoreByLettersCommand extends Command
{
    protected $signature = 'park:store-by-letters {--apply : завести, а не только показать}';

    protected $description = 'Завести стоящими цепочки, о приёме которых мы уже написали вендору';

    public function handle(StoreByLetters $store): int
    {
        $apply = (bool) $this->option('apply');
        $done = $skipped = 0;
        foreach ($store->waiting() as $candidate) {
            if ($why = $store->blocker($candidate)) {
                $skipped++;
                $this->line("— {$candidate->id} {$candidate->code} {$candidate->title()}: {$why}");

                continue;
            }
            $done++;
            $vehicle = $apply ? $store($candidate) : null;
            $this->line(($vehicle ? "ТС {$vehicle->id}" : 'завести')." {$candidate->code} {$candidate->title()}, принята {$candidate->stageLabel()}");
        }
        $this->info($apply ? "заведено {$done}, пропущено {$skipped}" : "заведётся {$done}, пропущено {$skipped}; с --apply заведёт");

        return self::SUCCESS;
    }
}
