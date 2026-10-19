<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Срок обязывающего — только у вендоров по комиссии (Совкомбанк; 19.10.2026, владелец: «в Тинь не нужно»): у остальных стереть. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offers')->whereNotNull('binding_until')
            ->whereNotIn('vendor_id', DB::table('vendors')->where('deal_format', 'commission')->select('id'))
            ->orWhere(fn ($q) => $q->whereNotNull('binding_until')->whereNull('vendor_id'))
            ->update(['binding_until' => null, 'binding_alerted_for' => null]);
    }

    public function down(): void {}
};
