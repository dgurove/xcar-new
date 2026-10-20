<?php

namespace App\Logistics\Listeners;

use App\Logistics\Events\TransportMoved;
use App\Logistics\TransportEvent;
use App\Support\Ago;

/**
 * Шаги вывоза — строкой истории задачи (`TransportEvent`, 18.10.2026): кто нажал, за кого, когда. Назначения пишут свои
 * действия (они знают, кто был до), шаги — здесь, по `TransportMoved`: созвон, документы, «Забрал», «Привёз», отмена, откат.
 */
final class RecordHistory
{
    public function handle(TransportMoved $e): void
    {
        $t = $e->transport;
        if (str_starts_with($e->step, 'undo:')) {
            TransportEvent::record($t, 'undone', $e->by, ['step' => substr($e->step, 5)]);

            return;
        }
        [$type, $payload] = match ($e->step) {
            'agreed' => ['called', ['at' => $t->pickup_at?->toIso8601String()]],
            'missed' => ['missed', []],
            'docs_sent' => ['docs_sent', ['track' => $t->docs_track]],
            'docs_received' => ['docs_received', []],
            'picked' => ['picked', ['keys' => $t->isPickup() ? $t->car?->keys_count : null, 'to' => $t->destinationLabel()]],
            // «Уже вывезли» задним числом — с датой, когда привезли на самом деле.
            'delivered' => ['delivered', ['to' => $t->destinationLabel(), 'day' => $t->delivered_at?->lt(now()->subMinutes(5)) ? Ago::day($t->delivered_at, time: true) : null]],
            'cancelled' => ['cancelled', ['reason' => $t->cancel_reason]],
            'auction' => ['auction', []],
            'auction_undone' => ['auction_undone', []],
            default => [null, []],
        };
        if ($type) {
            TransportEvent::record($t, $type, $e->by, $payload);
        }
    }
}
