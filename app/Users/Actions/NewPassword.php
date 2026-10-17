<?php

namespace App\Users\Actions;

use App\Support\Passwords;
use App\Users\User;
use Illuminate\Support\Str;

/**
 * Новый пароль человеку, которого завели руками или который свой забыл (18.10.2026): сгенерировать и показать шторкой
 * входа (`x-users.credentials`), как при `AddPerson`. Прежний пароль и «запомнить меня» перестают действовать. Кто
 * вправе — решает дверь: админ CRM — любому, менеджер — своему покупателю, логист — своему исполнителю.
 *
 * @return array<string, string> данные шторки входа
 */
final class NewPassword
{
    public function __invoke(User $user): array
    {
        $password = Passwords::readable();
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        return AddPerson::credentials($user, $password);
    }
}
