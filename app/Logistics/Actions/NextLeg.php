<?php

namespace App\Logistics\Actions;

use App\Logistics\Channel;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;

/**
 * Привезли на стоянку логиста — сразу следующий отрезок (18.10.2026, владелец: «потом доп задача, возможно другому
 * исполнителю, от стоянки до точки назначения»): та же машина, откуда — стоянка, куда — что было указано до стоянки
 * (`next_*`, «пока неизвестно» — пусто), перевозка без созвона с владельцем и документов, тот же логист — «У логиста»,
 * пока он не назначит исполнителя. Машина тем временем стоит на стоянке (место — `CarPlace::Depot`). Слушает «Привёз».
 */
final class NextLeg
{
    public function handle(TransportMoved $e): void
    {
        $t = $e->transport;
        if ($e->step !== 'delivered' || $t->destination !== Point::Depot || ! $t->depot || $t->nextLeg()->exists()) {
            return;
        }
        $leg = Transport::create([
            'car_id' => $t->car_id, 'origin' => $t->origin, 'kind' => TransportKind::Move, 'channel' => Channel::Logist,
            'logist_id' => $t->logist_id, 'state' => TransportState::Logist, 'previous_id' => $t->id,
            'from_depot_id' => $t->depot_id, 'from_address' => $t->depot->label(),
            // Кто отдаёт машину — тот, кто на стоянке (страница исполнителя и текст «Отправить» берут его как контакт).
            'from_contact' => $t->depot->contact_name, 'from_phone' => $t->depot->phone,
            'destination' => $t->next_destination, 'to_yard_id' => $t->next_to_yard_id, 'to_address' => $t->next_to_address,
            'reach' => $t->reach, 'comment' => $t->comment, 'client_id' => $t->client_id, 'created_by' => $e->by?->id,
        ]);
        TransportEvent::record($leg, 'opened', $e->by, ['from' => $t->depot->label()]);
        TransportAssigned::dispatch($leg, null);
    }
}
