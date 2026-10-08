<?php

namespace App\Users\Access;

use App\Support\Settings;
use App\Users\User;

/**
 * Тумблер замка менеджеров в CRM (владелец 07.10.2026: после выкладки выключен, включают сами). Пароли и ключи доступа
 * не трогаются: пока замок включён, менеджер с Telegram ими не войдёт (LoginController, PasskeyController), выключили —
 * снова входит, как раньше. Второй тумблер (08.10.2026) — только одно устройство, без Telegram и окна (Lock::lite).
 */
final class SwitchLock
{
    /** @param string $key Lock::SETTING — полная защита, Lock::SINGLE — только одно устройство */
    public function __invoke(string $key, bool $on, User $by): void
    {
        abort_unless(in_array($key, [Lock::SETTING, Lock::SINGLE], true), 404);
        Settings::put($key, $on, $by->id);
    }
}
