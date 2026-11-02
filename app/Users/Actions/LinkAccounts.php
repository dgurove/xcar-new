<?php

namespace App\Users\Actions;

use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Два аккаунта одного человека (02.11.2026, владелец: Сергей — управляющий парковки и менеджер xcar, «надо разделить чётко:
 * там он управляющий, а там только менеджер»; у каждого свой Telegram). Вход один: на хосте работает тот из двух, чей это
 * дом (`UseLinkedAccount`), роли не смешиваются. Дома должны быть разные — иначе непонятно, кем он на хосте.
 */
final class LinkAccounts
{
    public function __invoke(User $a, User $b): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['linked_user_id' => $message]);
        if ($a->is($b)) {
            $fail('Нельзя связать аккаунт с самим собой');
        }
        if (Surface::homeFor($a) === Surface::homeFor($b)) {
            $fail('У обоих аккаунтов один дом ('.Surface::homeFor($a)->label().') — связывают управляющего с менеджером и т. п.');
        }
        foreach ([$a, $b] as $u) {
            if ($u->isBuyer() || $u->isSuperBuyer() || $u->is_demo) {
                $fail('Покупателя и демо связать нельзя');
            }
        }
        DB::transaction(function () use ($a, $b) {
            app(UnlinkAccounts::class)($a);
            app(UnlinkAccounts::class)($b);
            $a->forceFill(['linked_user_id' => $b->id])->save();
            $b->forceFill(['linked_user_id' => $a->id])->save();
        });
    }
}
