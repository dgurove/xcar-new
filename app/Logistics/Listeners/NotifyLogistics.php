<?php

namespace App\Logistics\Listeners;

use App\Logistics\Channel;
use App\Logistics\Choices;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Notifications\Batch;
use App\Notifications\PickupTakenNotice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Кому что рассказать о вывозе. Уведомления в очереди, а события идут изнутри транзакций (письмо → предложение → вывоз,
 * назначение, «Забрал»): шлём после коммита — иначе работник очереди не найдёт задачу, а при откате пришла бы весть о
 * несуществующей.
 */
final class NotifyLogistics
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            TransportRequested::class => 'requested',
            TransportAssigned::class => 'assigned',
            TransportMoved::class => 'moved',
        ];
    }

    /** Новый вывоз без исполнителя — админам. */
    public function requested(TransportRequested $e): void
    {
        DB::afterCommit(fn () => Batch::send(User::logisticsLeads()->get(), TransportNotice::requested($e->transport)->forLeads('transport-requested')));
    }

    /**
     * Назначили: у логиста без исполнителя — ему, иначе исполнителю (прежнему поручение снято молча). Документы СДЭКом —
     * модераторам: оформить и отправить. Менеджер сделки забирает сам — не логистика: ему «Можно забирать» шлёт сделка
     * (`HandoverNotice`), здесь молчим.
     */
    public function assigned(TransportAssigned $e): void
    {
        DB::afterCommit(fn () => $this->sendAssigned($e));
    }

    private function sendAssigned(TransportAssigned $e): void
    {
        if ($e->batch) {
            return;
        }
        $t = $e->transport->fresh(['executor', 'logist']) ?? $e->transport;
        // Вывоз забрали у менеджера сделки (сняли или отдали другому) — ему: ехать не нужно (19.10.2026).
        $manager = $e->previous ? Choices::managerOf($t) : null;
        if ($manager && $e->previous->is($manager) && ! ($t->channel === Channel::Manager && $t->executor?->is($manager)) && ($offer = $t->saleOffer())) {
            $deal = $offer->deal()->first();
            $manager->notify(new PickupTakenNotice($t, $offer, $deal?->href() ?? '/deals'));
        }
        if ($t->isSelfPickup()) {
            return;
        }
        $to = $t->executor ?? ($t->channel === Channel::Logist ? $t->logist : null);
        if ($to && $to->isNot($e->previous)) {
            $to->notify(TransportNotice::assigned($t, $to));
        }
        if ($t->executor && $t->docs_by_cdek && ! $t->docs_sent_at) {
            Notification::send(User::withRole(Role::Moderator)->get(), TransportNotice::docsNeeded($t));
        }
    }

    /**
     * Ход: трек СДЭК — исполнителю; созвонились, вывезена, привёз, отменили — админам и логисту задачи (кроме того, кто
     * нажал). «Не дозвонился» и «получил документы» — без уведомлений: это видно в задаче.
     */
    public function moved(TransportMoved $e): void
    {
        DB::afterCommit(fn () => $this->sendMoved($e));
    }

    private function sendMoved(TransportMoved $e): void
    {
        $t = $e->transport;
        // Пачкой — сводку шлёт контроллер; менеджер забрал сам — это не логистика, его шаг пишет сделка.
        if ($e->batch || $t->isSelfPickup()) {
            return;
        }
        // Трек — исполнителю и логисту задачи: исполнители логиста обычно по ссылке без приложения, трек пересылает он.
        if ($e->step === 'docs_sent') {
            Notification::send(collect([$t->executor, $t->logist])->filter()->unique('id'), TransportNotice::docsSent($t));

            return;
        }
        if (! in_array($e->step, ['agreed', 'picked', 'delivered', 'cancelled'], true)) {
            return;
        }
        // Отмену логист и исполнитель узнают в Telegram (20.10.2026: водителя могли уже отправить); ведущим — лентой.
        $own = $e->step === 'cancelled' ? self::workers($t, $e->by) : collect();
        Notification::send($own, TransportNotice::cancelledYours($t));
        // Заявка логиста от своего заказчика — только ему (владелец 18.10.2026: «видит только она»).
        $skip = fn (User $u) => $u->is($e->by) || $own->contains('id', $u->id);
        // Логисту — сразу, как было; ведущим — тихо и пачкой (27.10.2026).
        $logist = collect([$t->logist])->filter()->reject($skip);
        $leads = ($t->isOrder() ? collect() : User::logisticsLeads()->get())->reject($skip)->reject(fn (User $u) => $logist->contains('id', $u->id));
        Notification::send($logist, TransportNotice::moved($t, $e->step));
        Batch::send($leads, TransportNotice::moved($t, $e->step)->forLeads('transport-moved', quiet: true));
    }

    /** Кто везёт задачу: логист и исполнитель, кроме нажавшего. */
    public static function workers(Transport $t, ?User $by): Collection
    {
        return collect([$t->logist, $t->executor])->filter()->unique('id')->reject(fn (User $u) => $by && $u->is($by))->values();
    }
}
