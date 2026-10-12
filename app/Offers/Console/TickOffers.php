<?php

namespace App\Offers\Console;

use App\Mail\Scan\AutoScan;
use App\Offers\Actions\AutoOffer;
use App\Offers\Actions\NotifyBidsClosed;
use App\Offers\Actions\NotifyViewers;
use App\Offers\Actions\PublishDueSlots;
use App\Offers\Actions\RemindEstimates;
use App\Offers\Actions\ShowInGallery;
use App\Support\HoldsSingleRun;
use App\Workflow\Actions\TickStages;
use Illuminate\Console\Command;

class TickOffers extends Command
{
    use HoldsSingleRun;

    protected $signature = 'offers:tick';

    protected $description = 'Часы предложений: слот 16:00, черновики в галерею, исходы по времени, просрочки, напоминания, «приём закрыт» владельцу, следующие волны показа, напоминания об оценке, черновики из писем';

    public function handle(PublishDueSlots $slots, TickStages $tick, NotifyBidsClosed $closed, NotifyViewers $waves, ShowInGallery $gallery, RemindEstimates $estimates, AutoOffer $auto): int
    {
        return $this->holdingSingleRun('offers:tick', function () use ($slots, $tick, $closed, $waves, $gallery, $estimates, $auto) {
            // Слот — первым: выпущенные им предложения тем же тиком уходят менеджерам одним уведомлением (волны).
            $counts = ['slot' => $slots(), 'gallery' => $gallery->due()] + $tick() + ['closing' => $closed->closing(), 'closed' => $closed(), 'waves' => $waves->due(), 'estimates' => $estimates->due()]
                // Письмо-предложение не завелось черновиком при приходе (выкладка), документы черновика не дочитаны.
                + ['drafts' => $auto->catchUp(), 'read' => AutoScan::sweep(offers: true)];
            $this->line(collect($counts)->filter()->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') ?: 'тихо');

            return self::SUCCESS;
        });
    }
}
