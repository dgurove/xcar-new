<?php

namespace App\Offers\Console;

use App\Mail\Scan\AutoScan;
use App\Offers\Actions\AutoOffer;
use App\Offers\Actions\NotifyBidsClosed;
use App\Offers\Actions\NotifyNewOffers;
use App\Offers\Actions\NotifyViewers;
use App\Offers\Actions\PublishDueSlots;
use App\Offers\Actions\RemindEstimates;
use App\Offers\Actions\SendToAuction;
use App\Offers\Actions\ShowInGallery;
use App\Support\HoldsSingleRun;
use App\Workflow\Actions\TickStages;
use Illuminate\Console\Command;

class TickOffers extends Command
{
    use HoldsSingleRun;

    protected $signature = 'offers:tick';

    protected $description = 'Часы предложений: слот 16:00, черновики в галерею, исходы по времени, просрочки, напоминания, «приём закрыт» владельцу, следующие волны показа, новые предложения пачкой после 5 минут тишины, напоминания об оценке, на аукцион без подтверждений, черновики из писем';

    public function handle(PublishDueSlots $slots, TickStages $tick, NotifyBidsClosed $closed, NotifyViewers $waves, NotifyNewOffers $digest, ShowInGallery $gallery, RemindEstimates $estimates, AutoOffer $auto, SendToAuction $auction): int
    {
        return $this->holdingSingleRun('offers:tick', function () use ($slots, $tick, $closed, $waves, $digest, $gallery, $estimates, $auto, $auction) {
            // Слот — первым: выпущенные им предложения тем же тиком уходят менеджерам одним уведомлением (волны).
            $counts = ['slot' => $slots(), 'gallery' => $gallery->due()] + $tick() + ['closing' => $closed->closing(), 'closed' => $closed(), 'auction' => $auction->due(), 'waves' => $waves->due(), 'digest' => $digest(), 'estimates' => $estimates->due()]
                // Письмо-предложение не завелось черновиком при приходе (выкладка); документы черновиков из писем дочитать —
                // раз в пять минут, как у парковки: проход читает письма всех их веток.
                + ['drafts' => $auto->catchUp(), 'read' => now()->minute % 5 === 0 ? AutoScan::sweep(offers: true) : 0];
            $this->line(collect($counts)->filter()->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') ?: 'тихо');

            return self::SUCCESS;
        });
    }
}
