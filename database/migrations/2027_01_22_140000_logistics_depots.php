<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стоянки логиста (18.10.2026, владелец: «вывезли в Кирове, поставили на стоянку — машина ждёт; потом другой исполнитель от
 * стоянки до точки назначения»): его справочник; задача может везти на стоянку (`destination = depot`, `depot_id`), конечная
 * цель тем временем — `next_*`; «Привёз» на стоянку заводит следующий отрезок (`previous_id`, откуда — `from_depot_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_depots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('logist_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 120);
            $t->foreignId('settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
            $t->string('address', 500)->nullable();
            $t->string('contact_name', 120)->nullable();
            $t->string('phone', 32)->nullable();
            $t->text('note')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['logist_id', 'archived_at']);
        });
        Schema::table('transports', function (Blueprint $t) {
            $t->foreignId('depot_id')->nullable()->after('to_yard_id')->constrained('logistics_depots')->nullOnDelete();
            $t->foreignId('from_depot_id')->nullable()->after('depot_id')->constrained('logistics_depots')->nullOnDelete();
            $t->foreignId('previous_id')->nullable()->after('from_depot_id')->constrained('transports')->nullOnDelete();
            $t->string('next_destination', 12)->nullable()->after('previous_id');
            $t->foreignId('next_to_yard_id')->nullable()->after('next_destination')->constrained('park_yards')->nullOnDelete();
            $t->string('next_to_address', 500)->nullable()->after('next_to_yard_id');
            $t->index('depot_id');
            $t->index('from_depot_id');
            $t->index('previous_id');
        });
    }

    public function down(): void
    {
        Schema::table('transports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('depot_id');
            $t->dropConstrainedForeignId('from_depot_id');
            $t->dropConstrainedForeignId('previous_id');
            $t->dropConstrainedForeignId('next_to_yard_id');
            $t->dropColumn(['next_destination', 'next_to_address']);
        });
        Schema::dropIfExists('logistics_depots');
    }
};
