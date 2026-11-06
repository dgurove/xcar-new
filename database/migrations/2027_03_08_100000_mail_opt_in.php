<?php

use App\Notifications\Categories;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Почта по согласию (06.11.2026): реально письма уходили только сотрудникам парковки (ящик storage@) — им включаем их
 * категории парковки и считаем почту подтверждённой, чтобы у них ничего не пропало. Остальным на почту — ничего, пока сами
 * не включат (до этого их письма уходили только в журнал: `MAIL_MAILER=log`).
 */
return new class extends Migration
{
    public function up(): void
    {
        User::withRole(Role::Parking, Role::ParkAdmin)->whereNotNull('email')->get()->each(function (User $u) {
            if (! $u->wantsMail()) {
                return;
            }
            $s = $u->notification_settings ?? [];
            $on = array_values(array_diff(array_intersect(['park', 'park_money'], array_keys(Categories::mail($u))), $s['off'] ?? []));
            $u->forceFill(['notification_settings' => ['mail_on' => $on] + $s, 'email_verified_at' => $u->email_verified_at ?? now()])->saveQuietly();
        });
    }

    public function down(): void {}
};
