<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Состояния «приём закрыт» у предложений больше нет: приём закрывает срок
 * bids_close_at. Закрытые — открытые с прошедшим сроком; этапы маршрутов без
 * состояния «closed»; отложенные CloseBids из очереди — класса больше нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offers')->where('state', 'closed')->where(fn ($q) => $q->whereNull('bids_close_at')->orWhere('bids_close_at', '>', now()))
            ->update(['bids_close_at' => DB::raw('updated_at')]);
        DB::table('offers')->where('state', 'closed')->update(['state' => 'open']);
        DB::table('workflow_stages')->where('offer_state', 'closed')->update(['offer_state' => null]);
        DB::table('jobs')->where('payload', 'like', '%CloseBids%')->delete();
    }

    public function down(): void {}
};
