<?php

namespace App\Park\Console;

use App\Mail\Scan\AutoScan;
use App\Park\Actions\AutoRequest;
use App\Park\Actions\AutoSale;
use App\Park\Actions\ReleaseByLetters;
use App\Park\Actions\ReportByLetters;
use App\Park\Actions\StoreByLetters;
use App\Park\Events\VehicleIdle;
use App\Park\Idle;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Console\Command;

/**
 * Часы стоянки: «стоит долго» — раз на машину, отметка в ТС, чтобы не повторяться (сроков у заявок нет с 10.10.2026).
 * Заодно подбирает цепочки, про которые мы уже написали вендору «приняли», а ТС не завели:
 * письмом их заводит `Mail\OnMessage`, а тут — всё, что пересчиталось без письма (`mail:read`, `mail:rebuild`).
 * И заявки из писем, что не завелись при письме (`AutoRequest::catchUp`), и ТС «Сразу в продажу», что не ушли (`AutoSale`).
 */
class TickPark extends Command
{
    protected $signature = 'park:tick';

    protected $description = 'Сроки заявок парковки и простой ТС';

    public function handle(StoreByLetters $store, ReleaseByLetters $release, ReportByLetters $report, AutoRequest $auto, AutoSale $sale): int
    {
        $now = now();
        $threshold = Idle::warn();
        $idle = Vehicle::where('state', VehicleState::Stored)->whereNull('idle_noticed_at')
            ->where('accepted_at', '<', $now->copy()->subDays($threshold))
            ->where(fn ($q) => $q->whereNull('offer_id')->orWhereHas('offer', fn ($o) => $o->whereIn('state', ['draft', 'archived'])->whereNull('slot_at')))->get();
        foreach ($idle as $v) {
            $v->forceFill(['idle_noticed_at' => $now])->save();
            VehicleIdle::dispatch($v, (int) $v->accepted_at->diffInDays($now));
        }
        $stored = $store->all();
        $released = $release->all();
        $reported = $report->all();
        $requested = $auto->catchUp();
        $swept = AutoScan::sweep();
        $sold = $sale->sweep();
        $this->info("стоят долго {$idle->count()}, заведено по письмам {$stored}, выдано по письмам {$released}, отчётов о приёме по письмам {$reported}, заявок из писем {$requested}, дочитываем {$swept}, в продажу {$sold}");

        return self::SUCCESS;
    }
}
