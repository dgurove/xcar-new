<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заявки парковки — только приём и выдача (владелец 10.10.2026): «Осмотр» и «Перестановка» заявками не заводились
 * (на проде их нет ни одной), перестановка — действие сразу. Такие строки (тестовые) стираются, иначе `RequestType`
 * не прочитает тип; осмотр, если был, остаётся записью без заявки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('park_requests')->whereIn('type', ['move', 'inspection'])->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('park_inspections')->whereIn('request_id', $ids)->update(['request_id' => null]);
        DB::table('park_requests')->whereIn('id', $ids)->delete();
    }

    public function down(): void {}
};
