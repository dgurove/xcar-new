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
        DB::afterCommit(fn () => Notification::send(User::withRole(Role::Admin)->get(), TransportNotice::requested($e->transport)));
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
        $t = $e->transport->fresh(['executorUser', 'coordinator']) ?? $e->transport;
        if ($t->executor === Executor::Manager) {
            return;
        }
        $to = $t->executorUser ?? ($t->executor === Executor::Logist ? $t->coordinator : null);
        if ($to && $to->isNot($e->previous)) {
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
        DB::afterCommit(fn () => $this->sendMoved($e));
    }

    private function sendMoved(TransportMoved $e): void
    {
        $t = $e->transport;
        // Пачкой — сводку шлёт контроллер; менеджер забрал сам — это не логистика, его шаг пишет сделка.
        if ($e->batch || $t->executor === Executor::Manager) {
            return;
        }
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
