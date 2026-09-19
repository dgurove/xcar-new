<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Тип события ленты ТС не влезал в 12 знаков: `invoice_voided` ронял аннулирование счёта. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('park_vehicle_events', function (Blueprint $t) {
            $t->string('type', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('park_vehicle_events', function (Blueprint $t) {
            $t->string('type', 12)->change();
        });
    }
};
