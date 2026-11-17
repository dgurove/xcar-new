<?php

namespace App\Offers\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Mail\Message;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Доп. запрос поставщику ушёл (17.11.2026, `Offers\ExtraRequest`): «Доп. запрос поставщику: прислать ПТС и согласовать
 * осмотр» в «Историю» машины со ссылкой на письмо и, когда идёт сделка с менеджером, ему в чат сообщением площадки —
 * «Попросили поставщика прислать ПТС и согласовать осмотр» (по CTTM Cargoline Роман не давал покупателя, пока не будет ПТС
 * и осмотра, и не знал, что мы их уже запросили). Ответ поставщика ему уходит через «Разобрать ответ» (`Disclose`).
 */
final class LogExtraRequest
{
    public function __invoke(Offer $offer, Message $message, ?User $by): void
    {
        $text = (string) ($message->requests['text'] ?? '');
        if ($text === '') {
            return;
        }
        $offer->log(OfferEventType::ExtraRequest, $by, ['text' => $text, 'letter' => $message->id]);

        $id = $offer->id;
        DB::afterCommit(function () use ($id, $text) {
            $offer = Offer::with('deal.buyer')->find($id);
            $deal = $offer?->deal;
            if (! $deal?->isActive() || ! $deal->buyer || ! $offer->chatOpenFor($deal->buyer)) {
                return;
            }
            app(PlatformMessage::class)(app(OpenChat::class)($offer, $deal->buyer, greet: false), 'Попросили поставщика '.$text);
        });
    }
}
