<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Название пишется «XCAR» (19.11.2026, владелец: «везде, где написано XCar»): тексты настроек, что лежат в базе, — шаги и
 * просьбы пути сделки, шаблоны писем, имя отправителя ящиков, группы, вендоры, метки. Историю (письма, сообщения,
 * уведомления) не трогаем.
 */
return new class extends Migration
{
    private const TABLES = ['workflows', 'workflow_blocks', 'workflow_stages', 'workflow_exits', 'requirements', 'mail_templates', 'mail_accounts', 'user_groups', 'vendors', 'tags'];

    public function up(): void
    {
        $this->swap('XCar', 'XCAR');
    }

    public function down(): void
    {
        $this->swap('XCAR', 'XCar');
    }

    private function swap(string $was, string $now): void
    {
        $columns = DB::select(
            "select table_name t, column_name c from information_schema.columns where table_schema = current_schema() and table_name = any(?) and data_type in ('text', 'character varying')",
            ['{'.implode(',', self::TABLES).'}'],
        );
        foreach ($columns as $col) {
            DB::table($col->t)->where($col->c, 'like', "%{$was}%")->update([$col->c => DB::raw('replace("'.$col->c."\", '{$was}', '{$now}')")]);
        }
    }
};
