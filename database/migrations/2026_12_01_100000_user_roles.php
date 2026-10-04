<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Несколько ролей у человека (04.10.2026, владелец: «и менеджером, и модератором одновременно… с админкой так же»):
 * `users.role` (одна строка) → `users.roles` (массив значений App\Users\Role). Покупатель — только один.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->jsonb('roles')->default('[]');
        });
        DB::statement('update users set roles = jsonb_build_array(role)');
        DB::statement('create index users_roles_gin on users using gin (roles)');
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 16)->default('visitor')->index();
        });
        // Назад — старшая из ролей: админ, модератор, менеджер, парковка, проверяющий, покупатель, посетитель.
        DB::statement("update users set role = coalesce((select r from unnest(array['admin','moderator','manager','parking','reviewer','buyer','visitor']) with ordinality o(r, i) where roles ? r order by i limit 1), 'visitor')");
        DB::statement('drop index if exists users_roles_gin');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('roles');
        });
    }
};
