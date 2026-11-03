<?php

namespace App\WhatsApp\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp — к аккаунту (`LinkCode`): тот, откуда пришёл код, а не номер из xcar. Один WhatsApp — один аккаунт: у прежнего
 * владельца привязка снимается. Привязал заново — личные идут с этого момента (`Queue` смотрит `whatsapp_linked_at`).
 */
final class LinkWhatsApp
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(User $user, string $jid, ?string $phone): void
    {
        DB::transaction(function () use ($user, $jid, $phone) {
            User::where('whatsapp_jid', $jid)->whereKeyNot($user->id)
                ->update(['whatsapp_jid' => null, 'whatsapp_phone' => null, 'whatsapp_linked_at' => null]);
            $user->forceFill(['whatsapp_jid' => $jid, 'whatsapp_phone' => $phone ?: null, 'whatsapp_linked_at' => now()])->save();
        });
        // Открытая шторка перейдёт на «Готово», профиль перерисуется.
        ($this->publish)(Topics::user($user), 'whatsapp', ['state' => 'linked']);
    }
}
