<?php

namespace App\Users\Actions;

use App\Users\Events\AccessDecided;
use App\Users\Role;
use App\Users\Section;
use App\Users\User;

/**
 * Решение по зарегистрировавшемуся: открыть доступ с ролью или отклонить.
 * Роль и доступ — одним нажатием: сайт закрыт, роль без допуска ничего не даёт.
 */
final class DecideAccess
{
    public function approve(User $user, Role $role, ?User $by = null): User
    {
        $access = $user->access ?? [];
        if ($role->isStaff() && ! in_array(Section::Park->value, $access, true)) {
            $access[] = Section::Park->value;
        }
        $user->forceFill(['role' => $role, 'access' => $access, 'approved_at' => now(), 'approved_by' => $by?->id, 'rejected_at' => null])->save();
        AccessDecided::dispatch($user, true, $by);

        return $user;
    }

    public function reject(User $user, ?User $by = null): User
    {
        $user->forceFill(['role' => Role::Visitor, 'access' => [], 'approved_at' => null, 'approved_by' => $by?->id, 'rejected_at' => now()])->save();
        AccessDecided::dispatch($user, false, $by);

        return $user;
    }
}
