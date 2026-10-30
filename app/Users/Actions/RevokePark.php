<?php

namespace App\Users\Actions;

use App\Park\Roles;
use App\Users\Role;
use App\Users\User;

/** Закрыть доступ к парковке: роли парковки снимаются; если других нет — человек закрыт целиком, как в CRM. */
final class RevokePark
{
    public function __construct(private DecideAccess $decide) {}

    public function __invoke(User $user, User $by): void
    {
        $roles = ($user->roles ?? collect())->reject(fn (Role $r) => in_array($r, Roles::ROLES, true))->values()->all();
        if ($roles === []) {
            $this->decide->reject($user, $by);
        } else {
            $user->update(['roles' => $roles, 'park_readonly' => false]);
        }
    }
}
