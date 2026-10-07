<?php

namespace App\Users\Access;

use App\Support\Settings;
use App\Users\User;

/**
 * Тумблер замка менеджеров в CRM (владелец 07.10.2026: после выкладки выключен, включают сами). Пароли и ключи доступа
 * не трогаются: пока замок включён, менеджер с Telegram ими не войдёт (LoginController, PasskeyController), выключили —
 * снова входит, как раньше.
 */
final class SwitchLock
{
    public function __invoke(bool $on, User $by): void
    {
        Settings::put(Lock::SETTING, $on, $by->id);
    }
}
