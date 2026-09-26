<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ветки писем ТС: «Наличие» считает их у каждой строки (тег «Писем нет»), без индекса — проход всей таблицы. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->index('vehicle_id'));
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropIndex(['vehicle_id']));
    }
};
