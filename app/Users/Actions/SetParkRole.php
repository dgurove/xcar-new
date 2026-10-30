<?php

namespace App\Users\Actions;

use App\Park\Roles;
use App\Support\Nav;
use App\Users\Role;
use App\Users\User;

/** Роль на парковке: администратор, управляющий или приёмщик и город; прочие роли человека (CRM, сайт) не трогаются. */
final class SetParkRole
{
    public function __invoke(User $user, string $kind, ?int $city): void
    {
        $roles = ($user->roles ?? collect())->reject(fn (Role $r) => in_array($r, Roles::ROLES, true))->values()->all();
        $roles[] = $kind === 'admin' ? Role::ParkAdmin : Role::Parking;
        $user->update(['roles' => $roles, 'park_readonly' => $kind === 'helper', 'park_city_id' => $kind === 'admin' ? null : $city]);
        Nav::forgetStaffCounts();
    }
}
