<?php

namespace App\Logistics\Listeners;

use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Logistics\TransportNotice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/** Кому что рассказать в логистике. */
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

    /** Межрегион без исполнителя — логистам. */
    public function requested(TransportRequested $e): void
    {
        Notification::send(User::withRole(Role::Logist)->get(), TransportNotice::requested($e->transport));
    }

    /** Назначили — исполнителю (кроме того, кто назначил сам себя). */
    public function assigned(TransportAssigned $e): void
    {
        $executor = $e->transport->executorUser;
        if ($executor && $executor->isNot($e->previous)) {
            $executor->notify(TransportNotice::assigned($e->transport));
        }
    }

    /** Забрали, сдали, отменили — тому, кто отправил в логистику, если это сделал не он. */
    public function moved(TransportMoved $e): void
    {
        $author = $e->transport->author;
        if ($author && $author->isNot($e->by) && $author->canLogistics()) {
            $author->notify(TransportNotice::moved($e->transport));
        }
    }
}
