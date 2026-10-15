<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Запросы номеров вендору — записями (16.10.2026, владелец: «историю отправок, чтобы предыдущие можно было скопировать»):
 * кто, когда и снимок номеров. У предложения вместо метки `requested_at|requested_by` — ссылка на запрос. Прежние метки
 * (15.10.2026, 28 номеров Т-Страхования) — запросом по паре «когда, кто».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->index()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $t->jsonb('refs');
            $t->timestamps();
        });
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('claim_request_id')->nullable()->index()->constrained()->nullOnDelete();
        });
        DB::statement(<<<'SQL'
            insert into claim_requests (vendor_id, user_id, refs, created_at, updated_at)
            select o.vendor_id, o.requested_by, jsonb_agg(c.claim_ref order by o.id), o.requested_at, o.requested_at
            from offers o join cars c on c.id = o.car_id
            where o.requested_at is not null and o.vendor_id is not null
            group by o.vendor_id, o.requested_by, o.requested_at
        SQL);
        DB::statement(<<<'SQL'
            update offers o set claim_request_id = r.id from claim_requests r
            where o.requested_at is not null and r.vendor_id = o.vendor_id and r.created_at = o.requested_at
              and r.user_id is not distinct from o.requested_by
        SQL);
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('requested_at')->nullable()->index();
            $t->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
        });
        DB::statement('update offers o set requested_at = r.created_at, requested_by = r.user_id from claim_requests r where r.id = o.claim_request_id');
        Schema::table('offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('claim_request_id'));
        Schema::dropIfExists('claim_requests');
    }
};
