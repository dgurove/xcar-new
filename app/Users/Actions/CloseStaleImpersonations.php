<?php

namespace App\Users\Actions;

use App\Users\Impersonation;
use Illuminate\Support\Facades\DB;

/**
 * Окно инкогнито закрыли, не нажав «Выйти»: запись входа за человека закрывается сроком сессии, чтобы в журнале
 * не висело «не вышел». Сама сессия к этому времени уже мертва — её отбивает Impersonated.
 */
final class CloseStaleImpersonations
{
    public function __invoke(): int
    {
        return Impersonation::whereNull('ended_at')->whereNotNull('used_at')
            ->where('used_at', '<', now()->subHours(Impersonation::HOURS))
            ->update(['ended_at' => DB::raw("used_at + interval '".Impersonation::HOURS." hours'")]);
    }
}
