<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тексты маршрутов в базе — как в заготовках (08.10.2026, обход перед финальной версией): без длинного тире, без
 * «машины» и «площадки» в том, что видят люди (имя этапа, просьба, текст блока).
 */
return new class extends Migration
{
    private const REPLACE = [
        ' — в гараж' => ', в гараж',
        'Машина уходит к Вам в гараж' => 'Автомобиль уходит к Вам в гараж',
        'Выдача на площадке поставщика' => 'Выдача у поставщика',
        'Сообщите адрес и получателя — направим' => 'Сообщите адрес и получателя, направим',
        'забирать с площадки поставщика' => 'забирать у поставщика',
        'Заберите его с площадки и' => 'Заберите его у поставщика и',
    ];

    public function up(): void
    {
        $this->apply(self::REPLACE);
    }

    public function down(): void
    {
        $this->apply(array_flip(self::REPLACE));
    }

    private function apply(array $map): void
    {
        foreach (['workflow_stages' => ['name', 'ask_title', 'ask_text'], 'workflow_blocks' => ['name', 'text']] as $table => $columns) {
            foreach ($columns as $column) {
                foreach ($map as $from => $to) {
                    DB::table($table)->where($column, 'like', '%'.$from.'%')
                        ->update([$column => DB::raw('replace('.$column.', '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')')]);
                }
            }
        }
    }
};
