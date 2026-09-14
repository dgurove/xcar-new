<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Состояния «закрыта» больше нет: приём закрывает срок. Закрытые — открытые с прошедшим сроком. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchases')->where('state', 'closed')->where(fn ($q) => $q->whereNull('offers_close_at')->orWhere('offers_close_at', '>', now()))
            ->update(['offers_close_at' => DB::raw('updated_at')]);
        DB::table('purchases')->where('state', 'closed')->update(['state' => 'open']);
    }

    public function down(): void {}
};
