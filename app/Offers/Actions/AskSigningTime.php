<?php

namespace App\Offers\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Notifications\SigningTimeNotice;
use App\Offers\Deal;
use App\Offers\Signing;
use Illuminate\Support\Facades\DB;

/**
 * Поставщик начал готовить договор (`Signing::asksTime`, вход в «Подготовку договора») — спросить менеджера заранее, когда
 * покупатель сможет подписать (11.11.2026, владелец: «как „Берём?“ — сообщение в чате с кнопками»): `deals.signing_asked_at`,
 * дело «Ваш ход» (`managerTask` kind `signing`), сообщение площадки в чат сделки с днями и временем (`chat_messages.ask =
 * signing`, `x-chat.signing-keys`) и `SigningTimeNotice`. Ответ — `SaveSigningWish`. Время уже есть — не спрашиваем.
 */
final class AskSigningTime
{
    public const CHAT_TEXT = 'Привет! Поставщик готовит договор. Когда покупатель сможет подписать? Выберите день и время — сразу запишем';

    public function __invoke(Deal $deal, bool $notify = true): bool
    {
        $deal->refresh();
        if ($deal->signing_asked_at || Signing::wishAt($deal) || ! $deal->buyer_id || ! $deal->isActive()) {
            return false;
        }
        $deal->update(['signing_asked_at' => now()]);
        if ($notify) {
            $id = $deal->id;
            DB::afterCommit(function () use ($id) {
                $deal = Deal::with(['buyer', 'offer'])->find($id);
                if (! $deal || ! Signing::wishDue($deal)) {
                    return;
                }
                if ($deal->offer->chatOpenFor($deal->buyer)) {
                    $chat = app(OpenChat::class)($deal->offer, $deal->buyer, greet: false);
                    app(PlatformMessage::class)($chat, self::CHAT_TEXT, ask: 'signing');
                }
                $deal->buyer->notify(new SigningTimeNotice($deal));
            });
        }

        return true;
    }
}
