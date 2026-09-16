<?php

namespace App\Users\Actions;

use App\Push\Subscription;
use App\Users\Events\AccessDecided;
use App\Users\Invite;
use App\Users\Role;
use App\Users\Section;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Решение по зарегистрировавшемуся: открыть доступ с ролью или отклонить.
 * Роль и доступ — одним нажатием: сайт закрыт, роль без допуска ничего не даёт.
 * Отклонить можно и уже допущенного — это и есть «закрыть доступ»: одноразовая
 * ссылка могла уйти не тому. Человек выходит со всех устройств, пуши и его
 * пригласительные ссылки гаснут, покупатели менеджера закрываются вместе с ним.
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
        $wasManager = $user->isManager();
        $user->forceFill(['role' => Role::Visitor, 'access' => [], 'approved_at' => null, 'approved_by' => $by?->id, 'rejected_at' => now()])->save();
        self::cutOff($user);
        if ($wasManager) {
            Invite::where('manager_id', $user->id)->whereNull('disabled_at')->update(['disabled_at' => now()]);
            foreach ($user->buyers()->whereNotNull('approved_at')->get() as $buyer) {
                $this->reject($buyer, $by);
            }
        }
        AccessDecided::dispatch($user, false, $by);

        return $user;
    }

    /** Сессии в базе и пуш-подписки — чтобы закрытый доступ закрылся сразу, а не при следующем входе. */
    public static function cutOff(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Subscription::where('user_id', $user->id)->delete();
    }
}
