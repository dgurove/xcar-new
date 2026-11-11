<?php

namespace App\Users\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Offers\Jobs\RetractOfferNotices;
use App\Users\User;
use Illuminate\Support\Str;

/**
 * Закрыть покупателю доступ к предложениям (11.11.2026, владелец: «непонятно, как у покупателя с доступом забрать доступ»):
 * войти больше не может (пароль и «запомнить меня» сброшены, сессии и пуши погашены — `DecideAccess::cutOff`), ничего не
 * видит и нигде не считается. Покупателем менеджера он остаётся: паспорт, сделки, группы и ручные показы — на месте,
 * «Дать доступ» вернёт всё как было (с новым паролем). Не путать с `DecideAccess::reject` — тот делает из человека посетителя.
 */
final class CloseBuyerAccess
{
    public function __invoke(User $buyer): User
    {
        $buyer->forceFill(['buyer_access_at' => null, 'password' => null, 'remember_token' => Str::random(60)])->save();
        DecideAccess::cutOff($buyer);
        RetractOfferNotices::forUser($buyer);
        app(Publisher::class)->refresh(Topics::user($buyer->id), ['/offers', '/account']);

        return $buyer;
    }
}
