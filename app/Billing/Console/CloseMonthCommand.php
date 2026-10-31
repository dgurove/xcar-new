<?php

namespace App\Billing\Console;

use App\Billing\Closing;
use App\Notifications\ParkNotice;
use App\Park\City;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\ClosingReady;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/** 1-го числа: посчитать, кому и за что выставить хранение за прошлый месяц, и позвать людей на экран закрытия. Само ничего не выставляет. */
class CloseMonthCommand extends Command
{
    protected $signature = 'billing:close-month {--month= : YYYY-MM, по умолчанию прошлый}';

    protected $description = 'Закрытие месяца: дайджест владельцу и сотрудникам парковки';

    public function handle(): int
    {
        $month = $this->option('month') ? Carbon::createFromFormat('!Y-m', $this->option('month'))->startOfMonth() : now()->subMonthNoOverflow()->startOfMonth();
        $items = Closing::items($month);
        $this->info($month->translatedFormat('F Y').': строк '.$items->count().', сумма '.round($items->sum('amount'), 2));
        if ($items->isEmpty()) {
            return self::SUCCESS;
        }
        NotifyOwner::dispatch(new ClosingReady($month, $items), park: true);
        // Закрытие месяца — управляющим и админам; управляющему и в Telegram. С городом — только строки его ТС (`Park\City`).
        $staff = User::parkStaff()->whereNotNull('approved_at')->whereNull('rejected_at')->get()->filter(fn (User $u) => $u->parkMoney());
        foreach ($staff->groupBy(fn (User $u) => City::of($u) ?? 0) as $city => $people) {
            $own = $city ? $items->filter(fn ($i) => (int) $i['vehicle']->city_id === (int) $city) : $items;
            if ($own->isNotEmpty()) {
                Notification::send($people, ParkNotice::closing($month, $own->count(), (float) $own->sum('amount')));
            }
        }

        return self::SUCCESS;
    }
}
