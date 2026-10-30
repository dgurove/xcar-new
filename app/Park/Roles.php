<?php

namespace App\Park;

use App\Users\Role;
use App\Users\User;

/**
 * Роль человека на парковке — одна: администратор парковки, управляющий или приёмщик (управляющий без правки,
 * `park_readonly`); у управляющего и приёмщика — город (`Park\City`). Ведёт её админ парковки на экране «Пользователи»
 * парковки (`Admin\UserController`, тот же экран, что в CRM); CRM роли парковки не правит.
 */
final class Roles
{
    public const KINDS = ['admin' => 'Администратор парковки', 'manager' => 'Управляющий', 'helper' => 'Приёмщик'];

    /** Роли, которые ведёт парковка. */
    public const ROLES = [Role::ParkAdmin, Role::Parking];

    public static function kindOf(User $user): ?string
    {
        return match (true) {
            $user->isParkAdmin() => 'admin',
            $user->isParking() => $user->park_readonly ? 'helper' : 'manager',
            default => null,
        };
    }

    /** «управляющий, Москва» — строчными, город у управляющего и приёмщика. */
    public static function label(User $user): ?string
    {
        $kind = self::kindOf($user);
        if (! $kind) {
            return null;
        }
        $city = $kind !== 'admin' ? City::name($user->park_city_id) : null;

        return mb_strtolower(self::KINDS[$kind]).($city ? ', '.$city : '');
    }
}
