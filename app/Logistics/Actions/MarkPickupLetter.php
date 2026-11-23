<?php

namespace App\Logistics\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Events\MessageFailed;
use App\Mail\Message;
use App\Logistics\Transport;
use App\Offers\OfferEventType;
use App\Users\User;

/**
 * Письмо поставщику о вывозе ушло или «Без письма — сообщили сами» (`Logistics\PickupLetters`, 23.11.2026): отметка на вывозе,
 * строка в «Истории» машины, страница и списки перечитываются. Письмо из окна ставится в очередь — не ушло (`MessageFailed`),
 * отметка снимается, и дело встаёт снова.
 */
final class MarkPickupLetter
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(Transport $t, string $kind, ?User $by, ?Message $letter = null): void
    {
        $t->forceFill($kind === 'act'
            ? ['supplier_act_at' => now(), 'supplier_act_message_id' => $letter?->id]
            : ['supplier_told_at' => now(), 'supplier_told_for' => $t->pickup_at, 'supplier_told_message_id' => $letter?->id])->save();
        $t->offer?->log(OfferEventType::Note, $by, ['text' => ($kind === 'act' ? 'Акт вывоза отправлен поставщику' : 'Дата вывоза сообщена поставщику')
            .($letter ? ' письмом' : ' не письмом')]);
        $this->refresh($t);
    }

    public static function failed(MessageFailed $e): void
    {
        $id = $e->message->id;
        foreach (Transport::where('supplier_told_message_id', $id)->orWhere('supplier_act_message_id', $id)->get() as $t) {
            $t->forceFill($t->supplier_act_message_id === $id ? ['supplier_act_at' => null, 'supplier_act_message_id' => null] : [])
                ->forceFill($t->supplier_told_message_id === $id ? ['supplier_told_at' => null, 'supplier_told_for' => null, 'supplier_told_message_id' => null] : [])
                ->save();
            $t->offer?->log(OfferEventType::Note, null, ['text' => 'Письмо поставщику о вывозе не ушло, напишите снова']);
            app(self::class)->refresh($t);
        }
    }

    private function refresh(Transport $t): void
    {
        if ($number = $t->offer?->number) {
            $this->publish->refresh(Topics::STAFF, ['/offers/'.$number]);
        }
    }
}
