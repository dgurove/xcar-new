<?php

namespace App\Users\Actions;

use App\Support\Surface;
use App\Users\PasswordLink;
use App\Users\User;

/**
 * Ссылка на новый пароль руками: менеджер — своему покупателю, админ — кому
 * угодно. Прежние неиспользованные гаснут, срок сутки, в базе только хэш.
 */
final class IssuePasswordLink
{
    public const HOURS = 24;

    public function __invoke(User $for, User $by): string
    {
        PasswordLink::where('user_id', $for->id)->whereNull('used_at')->update(['used_at' => now()]);

        [$plain, $hash] = PasswordLink::newToken();
        PasswordLink::create([
            'user_id' => $for->id,
            'token_hash' => $hash,
            'created_by' => $by->id,
            'expires_at' => now()->addHours(self::HOURS),
            'created_at' => now(),
        ]);

        return Surface::Site->url("/password/link/{$plain}");
    }
}
