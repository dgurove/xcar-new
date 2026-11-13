<?php

namespace App\Park\Console;

use App\Mail\Scan\AutoScan;
use App\Park\Actions\AcceptByLetters;
use App\Park\Actions\AutoRequest;
use App\Park\Actions\AutoSale;
use App\Park\Actions\ReleaseByLetters;
use App\Park\Actions\ReportByLetters;
use App\Park\Actions\StoreByLetters;
use App\Park\Actions\WithdrawSold;
use App\Park\Listeners\ReportDelivery;
use Illuminate\Console\Command;

/**
 * Часы стоянки: «стоит долго» — раз на машину, отметка в ТС, чтобы не повторяться (сроков у заявок нет с 10.10.2026).
 * Заодно подбирает цепочки, про которые мы уже написали вендору «приняли», а ТС не завели:
 * письмом их заводит `Mail\OnMessage`, а тут — всё, что пересчиталось без письма (`mail:read`, `mail:rebuild`).
 * И заявки из писем, что не завелись при письме (`AutoRequest::catchUp`), и ТС «Сразу в продажу», что не ушли (`AutoSale`).
 * И отчёты о приёме, что застряли «в пути» (`ReportDelivery::settle`). И продажи ТС, проданных не через нас (заявка на
 * выдачу, выдана), — в архив (`WithdrawSold`, 13.11.2026).
 */
class TickPark extends Command
{
    protected $signature = 'park:tick';

    protected $description = 'Сроки заявок парковки и простой ТС';

    public function handle(AcceptByLetters $accept, StoreByLetters $store, ReleaseByLetters $release, ReportByLetters $report, AutoRequest $auto, AutoSale $sale, ReportDelivery $delivery, WithdrawSold $withdraw): int
    {
        // «Стоит дольше 30 дней» снято (11.11.2026, владелец: «это наоборот хорошо, халявные бабки капают, не проблема»).
        $stored = $store->all();
        // Сначала приём по нашим письмам, потом отчёт о приёме: `ReportByLetters` смотрит только принятые.
        $accepted = $accept->all();
        $released = $release->all();
        $reported = $report->all();
        $requested = $auto->catchUp();
        $swept = AutoScan::sweep();
        $sold = $sale->sweep();
        $withdrawn = $withdraw->sweep();
        $settled = $delivery->settle();
        $this->info("заведено по письмам {$stored}, принято по письмам {$accepted}, выдано по письмам {$released}, отчётов о приёме по письмам {$reported}, заявок из писем {$requested}, дочитываем {$swept}, в продажу {$sold}, проданы не через нас {$withdrawn}, застрявших отчётов {$settled}");

        return self::SUCCESS;
    }
}
