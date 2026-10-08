<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Администратор парковки» — своя роль (08.10.2026, решение владельца): админ CRM без неё на парковку не входит.
 * Сразу она у админов, которые не работают в CRM менеджером или модератором — это Дмитрий и Андрей; у Екатерины
 * (админ, модератор и менеджер) её нет. Дальше её дают и снимают в «Пользователях» парковки.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update users set roles = roles || '["park_admin"]'::jsonb
            where roles @> '["admin"]'::jsonb and not roles @> '["park_admin"]'::jsonb
              and not roles @> '["manager"]'::jsonb and not roles @> '["moderator"]'::jsonb
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            update users set roles = roles - 'park_admin' where roles @> '["park_admin"]'::jsonb
        SQL);
    }
};
