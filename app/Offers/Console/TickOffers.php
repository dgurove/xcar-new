<?php

namespace App\Offers\Console;

use App\Support\HoldsSingleRun;
use App\Workflow\Actions\TickStages;
use Illuminate\Console\Command;

class TickOffers extends Command
{
    use HoldsSingleRun;

    protected $signature = 'offers:tick';

    protected $description = 'Часы предложений: исходы по времени, просрочки, напоминания';

    public function handle(TickStages $tick): int
    {
        return $this->holdingSingleRun('offers:tick', function () use ($tick) {
            $counts = $tick();
            $this->line(collect($counts)->filter()->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') ?: 'тихо');

            return self::SUCCESS;
        });
    }
}
