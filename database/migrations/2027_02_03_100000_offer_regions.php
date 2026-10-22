<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Регионы менеджера (22.10.2026, Сахиб с Урала: «слишком много машин, которые не нужны»). `users.offer_regions` — id
 * регионов, откуда ему показывать предложения; пусто — вся Россия. `offers.region_id` — регион машины: город продажи, иначе
 * названный в адресе осмотра (`Region::of`), его же держит `Offer::saving`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $t->index('region_id');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->jsonb('offer_regions')->nullable();
        });
        DB::statement('update offers o set region_id = s.region_id from settlements s where s.id = o.settlement_id and s.region_id is not null');
        $regions = DB::table('regions')->get(['id', 'match']);
        DB::table('offers')->whereNull('region_id')->whereNotNull('inspection_address')->select(['id', 'inspection_address'])->orderBy('id')
            ->each(function ($o) use ($regions) {
                $text = mb_strtolower($o->inspection_address);
                $best = null;
                foreach ($regions as $r) {
                    if (preg_match('/'.$r->match.'/u', $text, $m, PREG_OFFSET_CAPTURE) && ($best === null || $m[0][1] < $best[1])) {
                        $best = [$r->id, $m[0][1]];
                    }
                }
                if ($best) {
                    DB::table('offers')->where('id', $o->id)->update(['region_id' => $best[0]]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('offer_regions'));
        Schema::table('offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('region_id'));
    }
};
