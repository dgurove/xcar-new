<?php

namespace App\Park\Console;

use App\Park\Actions\AcceptByLetters;
use Illuminate\Console\Command;

/**
 * Разовый прогон по хвосту: ждущие ТС, о приёме которых мы уже написали вендору. Без `--apply` только показывает, что
 * будет принято, с какой датой и на какую парковку. Дальше это делает само (`Mail\OnMessage` при письме, `park:tick`).
 */
class AcceptByLettersCommand extends Command
{
    protected $signature = 'park:accept-by-letters {--apply : принять, а не только показать}';

    protected $description = 'Принять ждущие ТС, о приёме которых мы уже написали вендору';

    public function handle(AcceptByLetters $accept): int
    {
        $apply = (bool) $this->option('apply');
        $done = $skipped = 0;
        foreach ($accept->suspects() as $vehicle) {
            $letter = $accept->letter($vehicle);
            if (! $letter) {
                $skipped++;
                $this->line("— ТС {$vehicle->id} {$vehicle->ref} {$vehicle->titleWithYear()}: письмо раньше заявки или вендор после него отменил");

                continue;
            }
            $yard = $accept->yard($vehicle)?->name ?? 'без парковки';
            $accepted = $apply && $accept($vehicle);
            $done += ! $apply || $accepted ? 1 : 0;
            $this->line(($apply ? ($accepted ? 'принята' : 'не принята') : 'примется')." ТС {$vehicle->id} {$vehicle->ref} {$vehicle->titleWithYear()}: {$letter->date_at->format('d.m.Y H:i')}, {$yard}");
        }
        $this->info($apply ? "принято {$done}, пропущено {$skipped}" : "примется {$done}, пропущено {$skipped}; с --apply примет");

        return self::SUCCESS;
    }
}
