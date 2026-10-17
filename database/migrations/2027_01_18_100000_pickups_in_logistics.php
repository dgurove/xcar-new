<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вывоз целиком в логистике (17.10.2026, владелец): задача `transports` — от письма до «Привёз»: кто вывозит (Татьяна
 * со своими исполнителями, менеджер сделки, наши), созвон с владельцем, документы СДЭКом, приём с фото и ключами, куда
 * везёт. Партнёров с регионами, внешних перевозчиков текстом и развилки «внутри региона / между регионами» больше нет:
 * на проде их не заводили (7 перевозок «ждёт логиста» без исполнителя, партнёров 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->string('kind', 8)->default('pickup')->after('origin');
            $t->foreignId('coordinator_id')->nullable()->after('executor_id')->constrained('users')->nullOnDelete();
            $t->timestamp('pickup_at')->nullable()->after('from_address');
            $t->string('pickup_place', 500)->nullable()->after('pickup_at');
            $t->text('call_note')->nullable()->after('pickup_place');
            $t->jsonb('call_attempts')->nullable()->after('call_note');
            $t->timestamp('agreed_at')->nullable()->after('assigned_at');
            $t->boolean('docs_by_cdek')->default(false)->after('call_attempts');
            $t->string('docs_track', 60)->nullable()->after('docs_by_cdek');
            $t->timestamp('docs_sent_at')->nullable()->after('docs_track');
            $t->foreignId('docs_sent_by')->nullable()->after('docs_sent_at')->constrained('users')->nullOnDelete();
            $t->timestamp('docs_received_at')->nullable()->after('docs_sent_by');
            $t->unsignedInteger('distance_km')->nullable()->after('cost');
            $t->string('destination', 8)->nullable()->after('to_address');
            $t->text('pickup_note')->nullable()->after('destination');
            $t->string('cancel_reason', 500)->nullable()->after('cancelled_at');
            $t->timestamp('late_noticed_at')->nullable()->after('cancel_reason');
            $t->index('coordinator_id');
            $t->index('docs_sent_by');
        });
        // Парковка шлёт перевод между парковками — это перевозка, остальное — вывоз от владельца.
        DB::table('transports')->where('origin', 'park')->update(['kind' => 'move']);
        // Старые исполнители: партнёр — менеджер, наш водитель — наши; перевозчик текстом — аккаунта нет: открытая задача
        // снова ждёт выбора админа.
        DB::table('transports')->where('executor', 'partner')->update(['executor' => 'manager']);
        DB::table('transports')->where('executor', 'driver')->update(['executor' => 'staff']);
        DB::table('transports')->where('executor', 'carrier')->where('state', 'assigned')->update(['executor' => null, 'executor_id' => null, 'state' => 'new']);
        DB::table('transports')->where('executor', 'carrier')->update(['executor' => null, 'executor_id' => null]);
        // «Ждёт логиста» прежней схемы — вывоз без исполнителя: решает админ.
        DB::table('transports')->where('state', 'new')->update(['executor' => null]);

        Schema::table('transports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('from_region_id');
            $t->dropConstrainedForeignId('to_region_id');
            $t->dropColumn(['scope', 'carrier', 'receiver', 'ship_on', 'arrive_on']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('has_seal')->default(false);
            $t->foreignId('logist_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('logistics_city', 120)->nullable();
            $t->string('cdek_point', 300)->nullable();
            $t->index('logist_id');
            $t->dropColumn('partner_regions');
        });
        // Наши водители едут с печатью.
        DB::table('users')->whereRaw("roles @> '[\"driver\"]'::jsonb")->update(['has_seal' => true]);

        Schema::table('cars', function (Blueprint $t) {
            $t->unsignedSmallInteger('keys_count')->nullable();
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->boolean('pickup_on_arrival')->default(false);
        });
        // Совкомбанк: обязаны вывезти каждую — задача на вывоз встаёт вместе с предложением из письма.
        DB::table('vendors')->where('parser', 'sovcombank')->update(['pickup_on_arrival' => true]);
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('pickup_on_arrival'));
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn('keys_count'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('logist_id');
            $t->dropColumn(['has_seal', 'logistics_city', 'cdek_point']);
            $t->jsonb('partner_regions')->nullable();
        });
        Schema::table('transports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('coordinator_id');
            $t->dropConstrainedForeignId('docs_sent_by');
            $t->dropColumn(['kind', 'pickup_at', 'pickup_place', 'call_note', 'call_attempts', 'agreed_at',
                'docs_by_cdek', 'docs_track', 'docs_sent_at', 'docs_received_at', 'distance_km', 'destination', 'pickup_note', 'cancel_reason', 'late_noticed_at']);
            $t->string('scope', 9)->default('local');
            $t->foreignId('from_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $t->foreignId('to_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $t->string('carrier', 120)->nullable();
            $t->string('receiver', 200)->nullable();
            $t->date('ship_on')->nullable();
            $t->date('arrive_on')->nullable();
        });
    }
};
