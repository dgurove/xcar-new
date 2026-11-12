<?php

use App\Offers\Actions\SyncViewers;
use App\Offers\Offer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Публикация машины в сделке для других менеджеров — теми же правилами «кто → когда», что волны предложения (12.11.2026,
 * владелец: показ менеджерам, волны и «Опубликовать для других» — «по сути одно и то же», не хватало срока и «всем, кроме»).
 * Белый список `offer_deal_audience` и «показ всем» `deal_show_all_at` — одно: `deal_audience_rules` (пусто — всем сразу) от
 * `deal_published_at`, срок — `deal_show_until`; кто когда видит — `offer_viewers`, как у открытого.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->renameColumn('deal_show_all_at', 'deal_published_at');
            $t->jsonb('deal_audience_rules')->nullable();
        });

        // Белый список — выбранные сразу, остальные не видят.
        $lists = DB::table('offer_deal_audience as a')->join('offers as o', 'o.id', '=', 'a.offer_id')->where('o.state', 'sold')
            ->get(['a.offer_id', 'a.audience_type', 'a.audience_id'])->groupBy('offer_id');
        foreach ($lists as $offerId => $rows) {
            $rules = [...$rows->map(fn ($r) => ['type' => $r->audience_type, 'id' => (int) $r->audience_id, 'delay' => 0])->values()->all(),
                ['type' => 'rest', 'id' => null, 'delay' => null]];
            DB::table('offers')->where('id', $offerId)->whereNull('deal_published_at')
                ->update(['deal_audience_rules' => json_encode($rules), 'deal_published_at' => now()]);
        }
        Schema::dropIfExists('offer_deal_audience');

        // Кто видит — строками `offer_viewers`; кому уже писали прежде, второй раз не пишем.
        Offer::where('state', 'sold')->whereNotNull('deal_published_at')->get()->each(function (Offer $offer) {
            app(SyncViewers::class)($offer);
            DB::table('offer_viewers')->where('offer_id', $offer->id)->whereNull('notified_at')->update(['notified_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::create('offer_deal_audience', function (Blueprint $t) {
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->string('audience_type', 10);
            $t->unsignedBigInteger('audience_id');
            $t->primary(['offer_id', 'audience_type', 'audience_id']);
            $t->index(['audience_type', 'audience_id']);
        });
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn('deal_audience_rules');
            $t->renameColumn('deal_published_at', 'deal_show_all_at');
        });
    }
};
