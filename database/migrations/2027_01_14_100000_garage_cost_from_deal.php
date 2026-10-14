<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Закупочная гаражной машины — у её сделки (14.10.2026): строка гаража держала копию `deals.cost`, и после нового файла
 * Carcade копия разошлась со сделкой (603 840 против 718 800). Со сделкой своя колонка пуста — читает `Garage\Car::base`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('garage_cars')->whereNotNull('deal_id')->update(['cost' => null]);
    }

    public function down(): void {}
};
