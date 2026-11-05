<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\PayLink;
use Illuminate\Validation\ValidationException;

/**
 * Почта для чека у открытой ссылки — по желанию, из шторки ссылки, сотрудником или менеджером (05.11.2026). Обычно её
 * вписывает сам плательщик на странице оплаты (`StartCheckout`); знаем заранее — можно тут. У плательщика счёта почты не
 * было — встаёт и ему, следующая ссылка возьмёт её сама (`PayLinkController::store`).
 */
final class SetPayerEmail
{
    public function __invoke(PayLink $link, string $email): PayLink
    {
        if (! $link->isOpen()) {
            throw ValidationException::withMessages(['email' => 'Ссылка '.mb_strtolower($link->state->label())]);
        }
        $email = mb_strtolower(trim($email));
        $link->update(['payer_email' => $email]);
        $party = $link->invoice?->party;
        if ($party && ! $party->email) {
            $party->update(['email' => $email]);
        }

        return $link;
    }
}
