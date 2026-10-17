<?php

namespace App\Logistics\Listeners;

use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Executor;
use App\Logistics\TransportNotice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/** Кому что рассказать о вывозе. */
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
        Notification::send(User::withRole(Role::Admin)->get(), TransportNotice::requested($e->transport));
    }

    /**
     * Назначили: у логиста без исполнителя — ему, иначе исполнителю (прежнему поручение снято молча). Исполнитель без
     * печати — модераторам: оформить и отправить документы.
     */
    public function assigned(TransportAssigned $e): void
    {
        $t = $e->transport;
        $to = $t->executorUser ?? ($t->executor === Executor::Logist ? $t->coordinator : null);
        // Менеджер сделки, а забирать рано (Альфа до оплаты, Каркаде до согласия поставщика, 11.10.2026): молчим — «Можно
        // забирать» скажет `Notify::pickupOpened`.
        $early = $t->executor === Executor::Manager && $t->saleOffer()?->deal?->pickupHold() !== null;
        if ($to && $to->isNot($e->previous) && ! $early) {
            $to->notify(TransportNotice::assigned($t, $to));
        }
        if ($t->executorUser && $t->docs_by_cdek && ! $t->docs_sent_at) {
            Notification::send(User::withRole(Role::Moderator)->get(), TransportNotice::docsNeeded($t));
        }
    }

    /**
     * Ход: трек СДЭК — исполнителю; созвонились, вывезена, привёз, отменили — админам и логисту задачи (кроме того, кто
     * нажал). «Не дозвонился» и «получил документы» — без уведомлений: это видно в задаче.
     */
    public function moved(TransportMoved $e): void
    {
        $t = $e->transport;
        if ($e->step === 'docs_sent') {
            $t->executorUser?->notify(TransportNotice::docsSent($t));

            return;
        }
        if (! in_array($e->step, ['agreed', 'picked', 'delivered', 'cancelled'], true)) {
            return;
        }
        $to = User::withRole(Role::Admin)->get()->push($t->coordinator)->filter()
            ->unique('id')->reject(fn (User $u) => $u->is($e->by));
        Notification::send($to, TransportNotice::moved($t, $e->step));
    }
}
