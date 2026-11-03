<?php

namespace App\WhatsApp\Actions;

use App\Users\User;

/** Отключить WhatsApp: личные больше не идут. В самом WhatsApp ничего не пишем — человек сам нажал. */
final class UnlinkWhatsApp
{
    public function __invoke(User $user): void
    {
        $user->forceFill(['whatsapp_jid' => null, 'whatsapp_phone' => null, 'whatsapp_linked_at' => null])->save();
    }
}
