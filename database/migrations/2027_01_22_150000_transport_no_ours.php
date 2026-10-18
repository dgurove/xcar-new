<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «К нам» без адреса — не место (18.10.2026, владелец: «„к нам“ — это куда, непонятно»): у открытых задач такая точка
 * становится «пока неизвестно» — выберут парковку по названию, менеджера или адрес. Привезённые не трогаем: это история.
 */
return new class extends Migration
{
    public function up(): void
    {
        $open = ['new', 'logist', 'assigned', 'agreed', 'moving'];
        DB::table('transports')->whereIn('state', $open)->where('destination', 'ours')->update(['destination' => null]);
        DB::table('transports')->whereIn('state', $open)->where('next_destination', 'ours')->update(['next_destination' => null]);
    }

    public function down(): void {}
};
