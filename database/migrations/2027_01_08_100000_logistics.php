<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics XCar (10.10.2026): перевозка машины A → B — одна строка `transports`. Пришла из CRM (предложение) или с
 * парковки (ТС); внутри региона — партнёр (менеджер с регионом в `users.partner_regions`) или наш водитель, между
 * регионами — логист назначает внешнего перевозчика или нашего водителя, стоимость и сроки. Точки и «кто принимает» —
 * свободным текстом. Стоимость — счёт «мы должны» исполнителю (`invoice_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->jsonb('partner_regions')->nullable();             // id регионов, где менеджер — партнёр по логистике
        });

        Schema::create('transports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->nullable()->constrained('offers')->cascadeOnDelete();
            $t->foreignId('vehicle_id')->nullable()->constrained('park_vehicles')->cascadeOnDelete();
            $t->string('scope', 9);                               // local | intercity
            $t->foreignId('from_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $t->foreignId('to_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $t->string('executor', 8)->nullable();                // partner | driver | carrier
            $t->foreignId('executor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('carrier', 120)->nullable();               // внешний перевозчик текстом
            $t->string('from_address', 500)->nullable();
            $t->string('to_address', 500)->nullable();
            $t->string('receiver', 200)->nullable();              // кто принимает: имя и телефон
            $t->foreignId('to_yard_id')->nullable()->constrained('park_yards')->nullOnDelete(); // парковка в точке B (перевод ТС)
            $t->decimal('cost', 12, 2)->nullable();
            $t->date('ship_on')->nullable();
            $t->date('arrive_on')->nullable();
            $t->string('state', 10)->default('new');             // new | assigned | moving | delivered | cancelled
            $t->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $t->foreignId('garage_cost_id')->nullable()->constrained('garage_costs')->nullOnDelete(); // расход гаражной машины
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('picked_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['state', 'executor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transports');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('partner_regions'));
    }
};
