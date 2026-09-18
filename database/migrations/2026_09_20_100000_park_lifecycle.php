<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Стоянка как процесс: эвакуация — заявка с фазами (назначена, в пути),
 * ТС бывает «в пути» и «не привезена», осмотр — отдельная запись при приёме
 * и выдаче, документы вендору — с датами и вложениями, у площадки ряды и
 * место у ТС, у сотрудника — своя площадка и режим «только приёмка».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_requests', function (Blueprint $t) {
            $t->string('state', 12)->default('new')->change();      // + scheduled | in_progress
            $t->string('contact_name', 80)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->string('from_address', 255)->nullable();      // откуда забирать
            $t->string('carrier', 120)->nullable();           // перевозчик
            $t->smallInteger('distance_km')->nullable();
            $t->unsignedInteger('cost')->nullable();          // стоимость эвакуации, ₽ — считает этап денег
            $t->timestamp('started_at')->nullable();          // эвакуатор погрузил
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('overdue_at')->nullable();
        });
        DB::table('park_requests')->whereNull('done_by')->whereNotNull('done_at')->update(['done_by' => DB::raw('assignee_id')]);

        Schema::table('park_vehicles', function (Blueprint $t) {
            $t->string('state', 10)->default('expected')->change(); // + in_transit | cancelled
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->string('spot', 16)->nullable();               // ряд-место на площадке
            $t->timestamp('transit_started_at')->nullable();
            $t->unsignedInteger('mileage')->nullable();
            $t->smallInteger('fuel')->nullable();             // восьмые бака, 0..8
            $t->timestamp('idle_noticed_at')->nullable();     // «стоит долго» уже сказано
            $t->dropColumn('docs_done');
            $t->unique('offer_id');
        });

        Schema::create('park_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained('park_vehicles')->cascadeOnDelete();
            $t->foreignId('request_id')->nullable()->constrained('park_requests')->nullOnDelete();
            $t->string('kind', 8);                            // intake | release | pickup
            $t->timestamp('at');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('mileage')->nullable();
            $t->smallInteger('fuel')->nullable();
            $t->smallInteger('keys_count')->nullable();
            $t->jsonb('docs')->default('[]');                 // pts | sts | epts | service_book
            $t->jsonb('equipment')->default('[]');            // spare | jack | radio | mats | child_seat
            $t->jsonb('repair')->default('{}');               // {body, engine, chassis}: bool|null — акт Совкомбанка
            $t->jsonb('damage_zones')->default('[]');
            $t->text('damage_note')->nullable();              // по акту осмотра
            $t->text('transit_damage')->nullable();           // не по акту: транспортировка, хранение
            $t->text('missing_parts')->nullable();
            $t->text('replaced_units')->nullable();
            $t->string('signer_name', 120)->nullable();
            $t->timestamps();
            $t->index(['vehicle_id', 'kind']);
        });

        Schema::create('park_vehicle_docs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained('park_vehicles')->cascadeOnDelete();
            $t->string('kind', 20);                           // Park\DocKind
            $t->string('direction', 3)->default('out');       // out — мы вендору, in — ждём от него
            $t->string('state', 8)->default('pending');       // pending | sent | received
            $t->timestamp('at')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->foreignId('thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->unique(['vehicle_id', 'kind', 'direction']);
        });

        Schema::table('park_yards', fn (Blueprint $t) => $t->jsonb('rows')->default('[]'));   // [{name, n}]

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('park_yard_id')->nullable()->constrained('park_yards')->nullOnDelete();
            $t->boolean('park_readonly')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('park_yard_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('park_readonly'));
        Schema::table('park_yards', fn (Blueprint $t) => $t->dropColumn('rows'));
        Schema::drop('park_vehicle_docs');
        Schema::drop('park_inspections');
        Schema::table('park_vehicles', function (Blueprint $t) {
            $t->dropUnique(['offer_id']);
            $t->dropColumn(['cancelled_at', 'cancel_reason', 'spot', 'transit_started_at', 'mileage', 'fuel', 'idle_noticed_at']);
            $t->jsonb('docs_done')->default('[]');
        });
        Schema::table('park_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('done_by');
            $t->dropColumn(['contact_name', 'contact_phone', 'from_address', 'carrier', 'distance_km', 'cost', 'started_at', 'cancel_reason', 'reminded_at', 'overdue_at']);
        });
    }
};
