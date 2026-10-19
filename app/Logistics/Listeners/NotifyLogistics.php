<?php

namespace App\Logistics\Listeners;

use App\Logistics\Channel;
use App\Logistics\Choices;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Logistics\TransportNotice;
use App\Notifications\PickupTakenNotice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Events\Dispatcher;
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
        DB::afterCommit(fn () => Notification::send(User::logisticsLeads()->get(), TransportNotice::requested($e->transport)));
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
        if ($t->channel === Channel::Manager) {
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
        if ($e->batch || $t->channel === Channel::Manager) {
            return;
        }
        if ($e->step === 'docs_sent') {
            $t->executor?->notify(TransportNotice::docsSent($t));

            return;
        }
        if (! in_array($e->step, ['agreed', 'picked', 'delivered', 'cancelled'], true)) {
            return;
        }
        // Заявка логиста от своего заказчика — только ему (владелец 18.10.2026: «видит только она»).
        $to = ($t->isOrder() ? collect() : User::logisticsLeads()->get())->push($t->logist)->filter()
            ->unique('id')->reject(fn (User $u) => $u->is($e->by));
        Notification::send($to, TransportNotice::moved($t, $e->step));
    }
}
