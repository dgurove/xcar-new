<?php

namespace App\Billing\Console;

use App\Billing\Closing;
use App\Notifications\ParkNotice;
use App\Park\Area;
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
        $month = $this->option('month') ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth() : now()->subMonth()->startOfMonth();
        $items = Closing::items($month);
        $this->info($month->translatedFormat('F Y').': строк '.$items->count().', сумма '.round($items->sum('amount'), 2));
        if ($items->isEmpty()) {
            return self::SUCCESS;
        }
        NotifyOwner::dispatch(new ClosingReady($month, $items));
        // Закрытие месяца — тем, у кого на парковке открыты деньги.
        $staff = User::parkStaff()->whereNotNull('approved_at')->whereNull('rejected_at')->get()->filter(fn (User $u) => $u->canPark(Area::Money));
        Notification::send($staff, ParkNotice::closing($month, $items->count(), (float) $items->sum('amount')));

        return self::SUCCESS;
    }
}
