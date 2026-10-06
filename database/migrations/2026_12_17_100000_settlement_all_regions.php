<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Все регионы местами справочника (владелец 06.10.2026: «Пермский край и т. д., все регионы»), как Татарстан до них:
 * тип `рег`, без номера в подписи. Москва, Петербург и Севастополь — уже города.
 */
return new class extends Migration
{
    public function up(): void
    {
        $have = DB::table('settlements')->where('type', 'рег')->pluck('name')->all();
        $rows = DB::table('regions')->whereNotIn('plate', ['77', '78', '92'])->whereNotIn('name', $have)->get(['id', 'plate', 'name'])
            ->map(fn ($r) => ['name' => $r->name, 'type' => 'рег', 'region_code' => $r->plate, 'region_id' => $r->id, 'rank' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settlements')->insert($rows->all());
    }

    public function down(): void
    {
        DB::table('settlements')->where('type', 'рег')->where('name', '!=', 'Республика Татарстан')->delete();
    }
};
