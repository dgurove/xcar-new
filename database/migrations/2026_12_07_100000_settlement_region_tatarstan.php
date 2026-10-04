<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Республика Татарстан» местом в справочнике (владелец 05.10.2026: «бывает регион вместо города, хоть и нелогично»):
 * вендоры пишут город регионом. Тип `рег` — место-регион: без номера в подписи, ищется по любому слову.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settlements')->where('type', 'рег')->where('name', 'Республика Татарстан')->exists()) {
            return;
        }
        DB::table('settlements')->insert([
            'name' => 'Республика Татарстан', 'type' => 'рег', 'region_code' => '16',
            'region_id' => DB::table('regions')->where('plate', '16')->value('id'),
            'rank' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settlements')->where('type', 'рег')->where('name', 'Республика Татарстан')->delete();
    }
};
