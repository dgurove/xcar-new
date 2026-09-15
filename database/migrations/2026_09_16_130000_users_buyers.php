<?php

use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Покупатели менеджеров: у пользователя появляются логин, менеджер, приглашение,
 * по которому он пришёл, и список контактов, которые ему положено указывать.
 * Телефон больше не обязателен — покупатель без телефона и почты входит по логину.
 * Посетители (саморегистрация) удаляются: тестовые, регистрации без приглашения больше нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Посетителей удаляем через модель: медиатека сама уберёт аватары; уведомления и ключи — без FK.
        $visitors = User::where('role', Role::Visitor->value)->get();
        $ids = $visitors->pluck('id')->all();
        if ($ids) {
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->delete();
            DB::table('webauthn_credentials')->where('authenticatable_type', User::class)->whereIn('authenticatable_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            $visitors->each->delete();
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 11)->nullable()->change();
            $table->string('login', 32)->nullable()->after('phone');
            $table->foreignId('manager_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->foreignId('invite_id')->nullable()->after('manager_id')->constrained('invites')->nullOnDelete();
            $table->jsonb('contact_fields')->default('[]')->after('invite_id');
        });
        DB::statement('CREATE UNIQUE INDEX users_login_unique ON users (lower(login)) WHERE login IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_login_unique');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invite_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['login', 'contact_fields']);
        });
    }
};
