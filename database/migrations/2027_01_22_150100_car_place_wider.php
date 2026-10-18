<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Место машины шире 8 знаков (18.10.2026): «по адресу» (`elsewhere`, 17.10.2026) в `varchar(8)` не влезало — «Привёз» по
 * другому адресу падал на записи места. И стоянка логиста (`depot`) — с запасом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->string('car_place', 16)->nullable()->change());
        Schema::table('workflow_stages', fn (Blueprint $t) => $t->string('car_place', 16)->nullable()->change());
    }

    public function down(): void {}
};
