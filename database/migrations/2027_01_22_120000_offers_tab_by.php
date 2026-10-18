<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кто перевёл предложение во вкладку шага работы (18.10.2026, начальник: в «Без продажной» аватар того, кто завёл, у
 * времени прихода во вкладку — непонятно, что это). Прежним — автор того же события, что дало `tab_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('tab_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement(<<<'SQL'
            update offers o set tab_by = (
                select e.user_id from offer_events e
                where e.offer_id = o.id and (e.type = 'state_changed' or (e.type = 'updated'
                    and jsonb_exists_any(coalesce(e.payload::jsonb -> 'fields', '[]'::jsonb), array['floor_price', 'asking_price', 'slot_at', 'auction_at'])))
                order by e.created_at desc, e.id desc limit 1
            )
            where o.tab_at is not null
        SQL);
        // Пришло во вкладку заведением (события шага нет) — завёл тот, кто завёл.
        DB::statement(<<<'SQL'
            update offers o set tab_by = o.moderator_id
            where o.tab_by is null and o.moderator_id is not null and o.tab_at = o.created_at
        SQL);
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('tab_by'));
    }
};
