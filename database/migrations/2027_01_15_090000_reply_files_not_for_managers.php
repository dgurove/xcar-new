<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Файлы ответов страховой больше не открыты менеджеру сами (15.10.2026, владелец: оценка и бланки страховой — с вендором и
 * закупочной). «Видно менеджеру» у них ставил `ShareReplyFiles` автоматически — снимаем у всех с меткой письма.
 */
return new class extends Migration
{
    public function up(): void
    {
        $n = DB::update("update media set custom_properties = custom_properties::jsonb - 'managers'
            where jsonb_exists(custom_properties::jsonb, 'letter') and jsonb_exists(custom_properties::jsonb, 'managers')");
        Log::info("reply_files_not_for_managers: снято у {$n}");
    }
};
