<?php

namespace App\Offers\Console;

use App\Offers\Actions\NotifyBidsClosed;
use App\Offers\Actions\NotifyViewers;
use App\Offers\Actions\PublishDueSlots;
use App\Support\HoldsSingleRun;
use App\Workflow\Actions\TickStages;
use Illuminate\Console\Command;

class TickOffers extends Command
{
    use HoldsSingleRun;

    protected $signature = 'offers:tick';

    protected $description = 'Часы предложений: слот 16:00, исходы по времени, просрочки, напоминания, «приём закрыт» владельцу, следующие волны показа';

    public function handle(PublishDueSlots $slots, TickStages $tick, NotifyBidsClosed $closed, NotifyViewers $waves): int
    {
        return $this->holdingSingleRun('offers:tick', function () use ($slots, $tick, $closed, $waves) {
            // Слот — первым: выпущенные им предложения тем же тиком уходят менеджерам одним уведомлением (волны).
            $counts = ['slot' => $slots()] + $tick() + ['closed' => $closed(), 'waves' => $waves->due()];
            $this->line(collect($counts)->filter()->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') ?: 'тихо');

            return self::SUCCESS;
        });
    }
}
