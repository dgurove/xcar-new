<?php

namespace App\Park\Console;

use App\Park\Events\RequestCall;
use App\Park\Events\RequestDue;
use App\Park\Events\VehicleIdle;
use App\Park\Idle;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use Illuminate\Console\Command;

/**
 * Часы стоянки: напоминание за два часа до срока заявки и один раз о просрочке,
 * «стоит долго» — раз на машину. Отметки в самой заявке и ТС, чтобы не повторяться.
 */
class TickPark extends Command
{
    protected $signature = 'park:tick';

    protected $description = 'Сроки заявок стоянки и простой ТС';

    public function handle(): int
    {
        $now = now();
        $soon = Request::whereIn('state', RequestState::open())->whereNull('reminded_at')
            ->whereNotNull('planned_at')->where('planned_at', '<=', $now->copy()->addHours(2))->where('planned_at', '>', $now)->get();
        foreach ($soon as $r) {
            $r->forceFill(['reminded_at' => $now])->save();
            RequestDue::dispatch($r, false);
        }
        $late = Request::whereIn('state', RequestState::open())->whereNull('overdue_at')->whereNotNull('planned_at')->where('planned_at', '<', $now)->get();
        foreach ($late as $r) {
            $r->forceFill(['overdue_at' => $now])->save();
            RequestDue::dispatch($r, true);
        }
        // Пора перезвонить: напоминание один раз, отметка снимается новым сроком звонка.
        $calls = Request::whereIn('state', RequestState::open())->whereNull('reminded_at')->whereNotNull('next_call_at')->where('next_call_at', '<=', $now)->get();
        foreach ($calls as $r) {
            $r->forceFill(['reminded_at' => $now])->save();
            RequestCall::dispatch($r);
        }
        $threshold = Idle::warn();
        $idle = Vehicle::where('state', VehicleState::Stored)->whereNull('idle_noticed_at')
            ->where('accepted_at', '<', $now->copy()->subDays($threshold))
            ->where(fn ($q) => $q->whereNull('offer_id')->orWhereHas('offer', fn ($o) => $o->whereIn('state', ['draft', 'archived'])))->get();
        foreach ($idle as $v) {
            $v->forceFill(['idle_noticed_at' => $now])->save();
            VehicleIdle::dispatch($v, (int) $v->accepted_at->diffInDays($now));
        }
        if ($soon->isNotEmpty() || $late->isNotEmpty() || $calls->isNotEmpty()) {
            Nav::forgetStaffCounts();
        }
        $this->info("напомнено {$soon->count()}, перезвонить {$calls->count()}, просрочено {$late->count()}, стоят долго {$idle->count()}");

        return self::SUCCESS;
    }
}
