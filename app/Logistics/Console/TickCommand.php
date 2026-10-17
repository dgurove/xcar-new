<?php

namespace App\Logistics\Console;

use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
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
        Transport::inLogistics()->where('state', TransportState::Agreed)->where('pickup_at', '<', now()->subHours(2))
            ->where(fn ($q) => $q->whereNull('late_noticed_at')->orWhereColumn('late_noticed_at', '<', 'pickup_at'))
            ->with(['executorUser', 'coordinator', 'car'])->get()
            ->each(function (Transport $t) {
                // Заявку логиста от его заказчика админы не видят: просрочка — ему и исполнителю.
                $to = $t->isOrder() ? collect([$t->coordinator]) : User::logisticsLeads()->get();
                Notification::send($to->push($t->executorUser)->filter()->unique('id'), TransportNotice::late($t));
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
     * Сутки без шага: у логиста без исполнителя или назначен, а созвона нет (перевод парковки — без созвона). Вывоз менеджера
     * сделки сюда не попадает — он не в логистике (`Transport::scopeInLogistics`), его шаг ведёт маршрут сделки.
     *
     * @return Collection<int, Transport>
     */
    public static function stuck(): Collection
    {
        return Transport::inLogistics()->where('assigned_at', '<', now()->subDay())
            ->where(fn ($q) => $q->where('state', TransportState::Logist)
                ->orWhere(fn ($a) => $a->where('state', TransportState::Assigned)->where('kind', '!=', 'move')))
            ->with(['executorUser', 'coordinator', 'car'])->get();
    }

    /** Сводка админам — раз в день, с 10:00 по Москве. */
    private function digest(): void
    {
        $today = now('Europe/Moscow');
        if ($today->hour < 10 || ! Cache::add('logistics:digest:'.$today->toDateString(), true, now()->addDays(2))) {
            return;
        }
        $waiting = Transport::where('state', TransportState::New)->where('created_at', '<', now()->subDay())->count();
        $silent = self::stuck()->reject->isOrder()->count();
        if ($waiting + $silent > 0) {
            Notification::send(User::logisticsLeads()->get(), TransportNotice::stale($waiting, $silent));
        }
    }
}
