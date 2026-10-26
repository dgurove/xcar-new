<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кем заведено без человека (26.10.2026): `mail` — почта (`AutoOffer`), `migtorg` — Мигторг (`MigtorgDrafts`), `park` —
 * парковка (`AutoSale`). У даты в строке CRM — аватар системы (`x-ui.system-avatar`) вместо пустоты; одной колонкой, а не
 * чтением истории на каждую строку. Прошлое — из записи «Заведён» без автора.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->string('created_via', 16)->nullable());
        foreach (['mail', 'migtorg', 'park'] as $via) {
            DB::statement("update offers o set created_via = ? where created_via is null and o.moderator_id is null and exists (select 1 from offer_events e
                where e.offer_id = o.id and e.type = 'created' and e.user_id is null and jsonb_typeof(e.payload) = 'object' and jsonb_exists(e.payload, ?))", [$via, $via]);
        }
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('created_via'));
    }
};
