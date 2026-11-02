<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связанные аккаунты одного человека (02.11.2026, владелец: Сергей — управляющий на park.xcar.ru и менеджер на xcar.ru, «там он
 * управляющий, а там только менеджер», у каждого свой Telegram): ссылка на второй аккаунт, взаимная (`LinkAccounts`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('linked_user_id')->nullable()->unique()->constrained('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('linked_user_id'));
    }
};
