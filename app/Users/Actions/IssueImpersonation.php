<?php

namespace App\Users\Actions;

use App\Support\Surface;
use App\Users\Impersonation;
use App\Users\User;

/** Ссылка «Войти как»: одна на пару админ — человек, прежняя неоткрытая гаснет; 15 минут, в базе хэш. */
final class IssueImpersonation
{
    public function __invoke(User $for, User $by): string
    {
        abort_unless(Impersonation::allowed($by, $for), 404);

        Impersonation::where('admin_id', $by->id)->where('user_id', $for->id)->whereNull('used_at')
            ->update(['expires_at' => now()]);

        [$plain, $hash] = Impersonation::newToken();
        Impersonation::create([
            'admin_id' => $by->id,
            'user_id' => $for->id,
            'token_hash' => $hash,
            'expires_at' => now()->addMinutes(Impersonation::MINUTES),
            'created_at' => now(),
        ]);

        return Surface::Site->url("/login/as/{$plain}");
    }
}
