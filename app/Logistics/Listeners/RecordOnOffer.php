<?php

namespace App\Logistics\Listeners;

use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\TransportEvent;
use App\Offers\OfferEventType;
use App\Support\Ago;
use Illuminate\Events\Dispatcher;

/**
 * Ход вывоза — строкой в «Истории» предложения (17.10.2026): кто вывозит, созвонились, документы, вывезена, привёз,
 * отменили. «Не дозвонился» — нет: попытки видны в задаче.
 */
final class RecordOnOffer
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            TransportAssigned::class => 'assigned',
            TransportMoved::class => 'moved',
        ];
    }

    public function assigned(TransportAssigned $e): void
    {
        $t = $e->transport;
        $who = $t->executor ? 'исполнитель '.$t->executor->name : ($t->logist ? 'логист '.$t->logist->name : null);
        $t->saleOffer()?->log(OfferEventType::PickupAssigned, null, ['who' => $who ?? 'не выбран']);
    }

    public function moved(TransportMoved $e): void
    {
        $t = $e->transport;
        $text = match ($e->step) {
            'agreed' => 'созвонились, '.$t->next(),
            'docs_sent' => 'документы отправлены СДЭКом, трек '.$t->docs_track,
            'docs_received' => 'документы получены',
            'picked' => 'вывезена'.(($d = $t->destinationLabel()) ? ', '.$d : ''),
            // «Уже вывезли» задним числом — с датой: «привёз, к нам, 12 окт, 14:00».
            'delivered' => 'привёз'.(($d = $t->destinationLabel()) ? ', '.$d : '')
                .($t->delivered_at?->lt(now()->subMinutes(5)) ? ', '.Ago::day($t->delivered_at, time: true) : ''),
            'cancelled' => 'отменён'.($t->cancel_reason ? ': '.$t->cancel_reason : ''),
            'auction' => 'забрал аукцион',
            'auction_undone' => 'снова нужен',
            default => str_starts_with($e->step, 'undo:') ? 'отменили «'.(TransportEvent::STEPS[substr($e->step, 5)] ?? 'шаг').'»' : null,
        };
        if ($text) {
            $t->saleOffer()?->log(OfferEventType::Note, $e->by, ['text' => 'Вывоз: '.$text]);
        }
    }
}
