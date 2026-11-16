<?php

namespace App\Mail;

use App\Mail\Chains\NodeTitle;
use App\Vendors\Vendor;

/**
 * Вендор, от которого письмо, — по отправителю (17.11.2026, владелец: «раз у них всё равно аватарок нет, вместо заглушки с
 * инициалами — логотипы вендоров»). Только письмо вендора (`isFromVendor`) и только по адресу: разбор записал вендора
 * отправителя в письмо (`fields.vendor_id`, источник `sender`), а письмо, разобранное до того, как адрес выучили
 * (`BindSender`, `LearnSenders`), спрашивает списки вендоров (`Vendor::forSender`) — своей стороны ящика, потом другой.
 * Не по номеру убытка и не по ветке: письмо подрядчика или клиента в ветке Альфы — не Альфа.
 */
final class SenderVendor
{
    public static function of(Message $m): ?Vendor
    {
        if (! $m->isFromVendor()) {
            return null;
        }
        $vendors = once(fn () => Vendor::query()->get()->keyBy('id'));
        if (($id = (int) $m->field('vendor_id')) && $vendors->has($id)) {
            return $vendors->get($id);
        }
        $email = NodeTitle::email($m);
        $park = $m->account?->scope === Scope::Park;
        $found = Vendor::forSender($email, $park ? Scope::Park : Scope::Offers) ?? Vendor::forSender($email, $park ? Scope::Offers : Scope::Park);

        return $found ? ($vendors->get($found->id) ?? $found) : null;
    }
}
