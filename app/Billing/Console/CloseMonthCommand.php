<?php

namespace App\Billing\Console;

use App\Billing\Closing;
use App\Notifications\ParkNotice;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\ClosingReady;
use App\Users\Section;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/** 1-го числа: посчитать, кому и за что выставить хранение за прошлый месяц, и позвать людей на экран закрытия. Само ничего не выставляет. */
class CloseMonthCommand extends Command
{
    protected $signature = 'billing:close-month {--month= : YYYY-MM, по умолчанию прошлый}';

    protected $description = 'Закрытие месяца: дайджест владельцу и сотрудникам стоянки';

    public function handle(): int
    {
        $month = $this->option('month') ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth() : now()->subMonth()->startOfMonth();
        $items = Closing::items($month);
        $this->info($month->translatedFormat('F Y').': строк '.$items->count().', сумма '.round($items->sum('amount'), 2));
        if ($items->isEmpty()) {
            return self::SUCCESS;
        }
        NotifyOwner::dispatch(new ClosingReady($month, $items));
        $staff = User::where(fn ($q) => $q->whereJsonContains('access', Section::Park->value)->orWhere('role', 'admin'))->whereNotNull('approved_at')->whereNull('rejected_at')->where('park_readonly', false)->get();
        Notification::send($staff, ParkNotice::closing($month, $items->count(), (float) $items->sum('amount')));

        return self::SUCCESS;
    }
}
