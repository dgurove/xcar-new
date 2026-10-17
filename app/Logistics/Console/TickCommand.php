<?php

namespace App\Logistics\Console;

use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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

        self::stuck()->whereNull('nudged_at')->each(function (Transport $t) {
            $to = $t->state === TransportState::Logist ? $t->coordinator : $t->executorUser;
            $to?->notify(TransportNotice::callDue($t));
            $t->update(['nudged_at' => now()]);
        });

        $this->digest();

        return self::SUCCESS;
    }

    /**
     * Сутки без шага: у логиста без исполнителя или назначен, а созвона нет (перевод парковки — без созвона). Отсчёт — от
     * назначения или от того, когда забирать стало можно: менеджеру сделки, пока рано (Альфа до оплаты, Каркаде до согласия
     * поставщика, `Deal::pickupHold`), напоминать не о чем, и такая задача не «зависла» ни для него, ни в сводке.
     *
     * @return Collection<int, Transport>
     */
    public static function stuck(): Collection
    {
        $day = now()->subDay();

        return Transport::where('assigned_at', '<', $day)
            ->where(fn ($q) => $q->where('state', TransportState::Logist)
                ->orWhere(fn ($a) => $a->where('state', TransportState::Assigned)->where('kind', '!=', 'move')))
            ->with(['executorUser', 'coordinator', 'car', 'offer.deal.offer.vendor'])->get()
            ->filter(function (Transport $t) use ($day) {
                $deal = $t->executor === Executor::Manager ? $t->saleOffer()?->deal : null;
                if ($deal?->pickupHold() !== null) {
                    return false;
                }
                $since = collect([$deal?->agreed_at, $deal?->prepaid_at])->filter()->max();

                return $since === null || $since->lt($day);
            })->values();
    }

    /** Сводка админам — раз в день, с 10:00 по Москве. */
    private function digest(): void
    {
        $today = now('Europe/Moscow');
        if ($today->hour < 10 || ! Cache::add('logistics:digest:'.$today->toDateString(), true, now()->addDays(2))) {
            return;
        }
        $waiting = Transport::where('state', TransportState::New)->where('created_at', '<', now()->subDay())->count();
        $silent = self::stuck()->count();
        if ($waiting + $silent > 0) {
            Notification::send(User::withRole(Role::Admin)->get(), TransportNotice::stale($waiting, $silent));
        }
    }
}
