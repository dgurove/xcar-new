<?php

namespace App\Offers\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Users\User;

/**
 * «Напомнить менеджеру» приложить подписанный договор (26.11.2026, вкладка «Ждём договор»; владелец: Алина «видит, подгрузили
 * договор или нет, и если что сама подгрузит или напомнит менеджеру»): сообщением в чат сделки от площадки — оно же уведомление.
 */
final class NudgeSignedContract
{
    public static function run(Deal $deal, User $by): void
    {
        $chat = app(OpenChat::class)($deal->offer, $deal->buyer, greet: false);
        app(PlatformMessage::class)($chat, 'Приложите, пожалуйста, фото подписанного договора купли-продажи: '.$deal->offer->title()
            .'. Кнопка «Приложить подписанный» в сделке');
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Напомнили менеджеру приложить подписанный договор']);
    }
}
