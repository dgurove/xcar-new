<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Запрет шеринга на время оценки — личный (19.10.2026, владелец): галку «Запретить шеринг» ставила оценка — снимаем.
 * Поставленную админом оценка не помечала (`share_locked_by_estimate` у неё ложь, правка галки его гасила), её не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offers')->where('share_locked_by_estimate', true)->where('share_locked', true)->update(['share_locked' => false]);
    }

    public function down(): void {}
};
