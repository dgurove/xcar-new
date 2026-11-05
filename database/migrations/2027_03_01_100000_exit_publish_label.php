<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Исход «Опубликовать» у этапа «Черновик» — «Выставить в продажу» (05.11.2026, владелец: «не понимаю разницы между „Выставить
 * в продажу“ и „Опубликовать“»): действие одно, слот — только время выхода. Исход ищут по состоянию этапа (`Stage::exitInto`),
 * не по подписи. Правится только прежняя подпись: переименованное руками остаётся.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_exits')->where('actor', 'staff')->where('label', 'Опубликовать')->update(['label' => 'Выставить в продажу']);
    }

    public function down(): void
    {
        DB::table('workflow_exits')->where('actor', 'staff')->where('label', 'Выставить в продажу')->update(['label' => 'Опубликовать']);
    }
};
