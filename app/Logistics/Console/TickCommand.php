<?php

namespace App\Logistics\Console;

use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Notifications\Batch;
use App\Notifications\SentOnce;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // День вывоза прошёл целиком — наутро, не ночью (22.10.2026: вывоз — день без времени, забирают когда угодно).
        Transport::inLogistics()->where('state', TransportState::Agreed)->where('pickup_at', '<', now()->startOfDay())
            ->when(now()->hour < 9, fn ($q) => $q->whereRaw('false'))
            ->where(fn ($q) => $q->whereNull('late_noticed_at')->orWhereColumn('late_noticed_at', '<', 'pickup_at'))
            ->with(['executor', 'logist', 'car'])->get()
            ->each(function (Transport $t) {
                // Заявку логиста от его заказчика админы не видят: просрочка — ему и исполнителю. Логисту — всегда: его
                // исполнители обычно по ссылке, без приложения (20.10.2026).
                $to = $t->isOrder() ? collect() : User::logisticsLeads()->get();
                // Отправка и отметка — одной транзакцией (очередь в той же базе): упало посреди — следующий тик пришлёт.
                DB::transaction(function () use ($t, $to) {
                    Notification::send($to->push($t->logist)->push($t->executor)->filter()->unique('id'), TransportNotice::late($t));
                    $t->update(['late_noticed_at' => now()]);
                });
            });

        $this->waiting();

        self::stuck()->whereNull('nudged_at')->each(function (Transport $t) {
            $to = $t->state === TransportState::Logist ? $t->logist : $t->executor;
            DB::transaction(function () use ($t, $to) {
                $to?->notify(TransportNotice::callDue($t));
                $t->update(['nudged_at' => now()]);
            });
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
            ->with(['executor', 'logist', 'car'])->get();
    }

    /** Новый вывоз два часа без исполнителя — ведущим, один раз, днём 9–20 по Москве; старше суток — в утренней сводке. */
    private function waiting(): void
    {
        $hour = now('Europe/Moscow')->hour;
        if ($hour < 9 || $hour >= 20) {
            return;
        }
        $due = Transport::where('state', TransportState::New)->where('undecided', false)
            ->whereBetween('created_at', [now()->subDay(), now()->subHours(2)])->with('car')->get()
            ->reject->isOrder()->reject(fn (Transport $t) => Cache::has('logistics:waiting:'.$t->id));
        if ($due->isNotEmpty()) {
            $leads = User::logisticsLeads()->get();
            // «Отправлено» — после отправки (`SentOnce`): упало посреди — следующий тик.
            $due->each(fn (Transport $t) => SentOnce::run('logistics:waiting:'.$t->id, now()->addDays(2), fn () => Batch::send($leads, TransportNotice::waiting($t))));
        }
    }

    /** Сводка админам — раз в день, с 10:00 по Москве. */
    private function digest(): void
    {
        $today = now('Europe/Moscow');
        if ($today->hour < 10) {
            return;
        }
        // «Отправлено» — после отправки (`SentOnce`): упало посреди — следующий тик (через 15 минут), а не завтра.
        SentOnce::run('logistics:digest:'.$today->toDateString(), now()->addDays(2), function () {
            $waiting = Transport::where('state', TransportState::New)->where('undecided', false)->where('created_at', '<', now()->subDay())->count();
            $silent = self::stuck()->reject->isOrder()->count();
            if ($waiting + $silent > 0) {
                Notification::send(User::logisticsLeads()->get(), TransportNotice::stale($waiting, $silent));
            }
        });
    }
}
