<?php

namespace App\Logistics\Console;

use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Часы логистики (каждые 15 минут):
 * — дата вывоза прошла, «Забрал» нет — админам и исполнителю, раз на задачу (перенесли — снова);
 * — сутки без шага (19.01.2027): назначен, а «Созвонились» нет — исполнителю; у логиста, а своего не назначил — логисту;
 *   раз на шаг (`nudged_at`, сбрасывает смена шага — `Transport::saving`);
 * — админам раз в день в 10:00 сводка: сколько дольше суток без исполнителя и без созвона.
 */
final class TickCommand extends Command
{
    protected $signature = 'logistics:tick';

    protected $description = 'Вывозы, не забранные в срок, и зависшие шаги';

    public function handle(): int
    {
        Transport::where('state', TransportState::Agreed)->where('pickup_at', '<', now()->subHours(2))
            ->where(fn ($q) => $q->whereNull('late_noticed_at')->orWhereColumn('late_noticed_at', '<', 'pickup_at'))
            ->with(['executorUser', 'car'])->get()
            ->each(function (Transport $t) {
                Notification::send(User::withRole(Role::Admin)->get()->push($t->executorUser)->filter()->unique('id'), TransportNotice::late($t));
                $t->update(['late_noticed_at' => now()]);
            });

        self::silent()->whereNull('nudged_at')->with(['executorUser', 'coordinator', 'car'])->get()
            ->each(function (Transport $t) {
                $to = $t->state === TransportState::Logist ? $t->coordinator : $t->executorUser;
                // Менеджеру сделки, пока забирать рано (Альфа до оплаты, Каркаде до согласия), — молчим, как при поручении.
                $early = $t->executor === Executor::Manager && $t->saleOffer()?->deal?->pickupHold() !== null;
                if (! $early) {
                    $to?->notify(TransportNotice::callDue($t));
                }
                $t->update(['nudged_at' => now()]);
            });

        $this->digest();

        return self::SUCCESS;
    }

    /** Сутки без шага: у логиста без исполнителя или назначен, а созвона нет (перевод парковки — без созвона). */
    public static function silent()
    {
        return Transport::where(fn ($q) => $q
            ->where(fn ($l) => $l->where('state', TransportState::Logist)->where('assigned_at', '<', now()->subDay()))
            ->orWhere(fn ($a) => $a->where('state', TransportState::Assigned)->where('kind', '!=', 'move')->where('assigned_at', '<', now()->subDay())));
    }

    /** Сводка админам — раз в день, с 10:00 по Москве. */
    private function digest(): void
    {
        $today = now('Europe/Moscow');
        if ($today->hour < 10 || ! Cache::add('logistics:digest:'.$today->toDateString(), true, now()->addDay())) {
            return;
        }
        $waiting = Transport::where('state', TransportState::New)->where('created_at', '<', now()->subDay())->count();
        $silent = self::silent()->count();
        if ($waiting + $silent > 0) {
            Notification::send(User::withRole(Role::Admin)->get(), TransportNotice::stale($waiting, $silent));
        }
    }
}
