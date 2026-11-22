<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ящик почты CRM по умолчанию — deal@ (22.11.2026, владелец: «по умолчанию deal@xcar.ru, а не „Все ящики“»). Запомненное
 * пустое «Все ящики» стирается, чтобы почта открылась на deal@; выбрать «Все ящики» снова можно у заголовка.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update users set list_prefs = jsonb_set(list_prefs, '{offers-mail}', (list_prefs->'offers-mail') - 'account')
            where list_prefs->'offers-mail'->>'account' = ''");
    }

    public function down(): void {}
};
