<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Когда заплатили поставщику (19.10.2026, срок обязывающего предложения: до оплаты поставщику сделка ещё может потерять
 * машину). Идущим сделкам — по журналу: выход «Поставщику оплачено» или из этапа перевода с Р.О.С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('supplier_paid_at')->nullable());
        $rows = DB::table('offer_events as e')->join('deals as d', 'd.offer_id', '=', 'e.offer_id')
            ->where('d.state', 'active')->where('e.type', 'stage_entered')
            ->whereRaw("lower(e.payload->>'exit') in ('поставщику оплачено', 'переведено поставщику')")
            ->where('e.created_at', '>=', DB::raw('d.created_at'))
            ->groupBy('d.id')->selectRaw('d.id, max(e.created_at) as at')->get();
        foreach ($rows as $row) {
            DB::table('deals')->where('id', $row->id)->update(['supplier_paid_at' => $row->at]);
        }
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('supplier_paid_at'));
    }
};
