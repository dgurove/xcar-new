<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Покупатели с доступом и без (11.11.2026, владелец: «менеджеры пугаются, когда у покупателя без логина и пароля написано
 * „видит все предложения“»; Тагир у Армена — «видит 91»). `users.buyer_access_at` — когда дали доступ к предложениям;
 * null — только данные для сделок. Доступ есть у тех, кто может войти: логин, телефон или почта и пароль.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('buyer_access_at')->nullable();
        });
        DB::table('users')
            ->where(fn ($q) => $q->whereJsonContains('roles', 'buyer')->orWhereJsonContains('roles', 'super_buyer'))
            ->whereNotNull('password')
            ->whereRaw("(coalesce(login, '') <> '' or coalesce(phone, '') <> '' or coalesce(email, '') <> '')")
            ->update(['buyer_access_at' => DB::raw('coalesce(approved_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('buyer_access_at'));
    }
};
