<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Кто платит по ссылке — меняется (06.10.2026, владелец: «непонятно, почему невозможно сменить ФИО плательщика»).
 * Ссылка та же: её уже могли отправить, адрес не меняется — меняются имя и почта для чека. Плательщик уже на странице
 * оплаты (попытка ещё идёт) — так не трогаем: новая ссылка (`CreatePayLink`), прежняя гаснет.
 */
final class ChangePayLinkPayer
{
    public function __construct(private CreatePayLink $create) {}

    public function __invoke(PayLink $link, User $by, PayerKind $kind, ?User $payer = null, ?string $email = null): PayLink
    {
        if ($link->state !== PayLinkState::Open) {
            throw ValidationException::withMessages(['payer' => 'Ссылка уже не действует']);
        }
        [$name, $email] = $kind === PayerKind::Buyer ? [$payer?->name, $payer?->email ?: $email] : [$by->name, $by->email ?: $email];
        if (filled($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Проверьте почту: на неё придёт чек']);
        }
        if ($link->attempts->contains(fn ($a) => $a->isPending())) {
            return ($this->create)($link->invoice, $by, (float) $link->amount, $kind, $payer, null, null, $email);
        }
        $link->update(['payer_kind' => $kind, 'payer_user_id' => $kind === PayerKind::Buyer ? $payer?->id : $by->id, 'payer_name' => $name, 'payer_email' => $email]);
        $link->invoice->offer?->log(OfferEventType::Note, $by, ['text' => 'По ссылке на оплату счёта '.$link->invoice->label().' теперь платит '.$name]);

        return $link;
    }
}
