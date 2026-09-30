<?php

namespace App\Users\Actions;

use App\Support\Surface;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Support\Str;

/** Ссылка «Войти как»: одна на пару админ — человек, прежняя неоткрытая гаснет; 15 минут, в базе хэш. */
final class IssueImpersonation
{
    public function __invoke(User $for, User $by): string
    {
        abort_unless(Impersonation::allowed($by, $for), 404);

        Impersonation::where('admin_id', $by->id)->where('user_id', $for->id)->whereNull('used_at')
            ->update(['expires_at' => now()]);

        $plain = Str::random(40);
        Impersonation::create([
            'admin_id' => $by->id,
            'user_id' => $for->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes(Impersonation::MINUTES),
            'created_at' => now(),
        ]);

        return Surface::Site->url("/login/as/{$plain}");
    }
}
