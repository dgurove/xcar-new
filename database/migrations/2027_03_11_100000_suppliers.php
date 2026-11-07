<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поставщик (07.11.2026, владелец: «люди из Альфы, Т-Страхования, Совкома» сами заводят нам черновики и вписывают цены на
 * partner.xcar.ru): у человека — его вендор (`users.vendor_id`, у ссылки — `invites.vendor_id`). Закупочная у всех
 * поставщиков — от оценочной по диапазонам (`floor_rules`: [[до, плюс ₽, плюс %], …], последняя строка без границы):
 * умолчание у вендора, своё — у предложения (null — как у вендора). Альфе — прежние ступени `Pricing::RULES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete());
        Schema::table('invites', fn (Blueprint $t) => $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete());
        Schema::table('vendors', fn (Blueprint $t) => $t->jsonb('floor_rules')->nullable());
        Schema::table('offers', fn (Blueprint $t) => $t->jsonb('floor_rules')->nullable());

        DB::table('vendors')->where('floor_by_value', true)->update(['floor_rules' => json_encode([[500_000, 50_000, 0], [2_000_000, 0, 10], [null, 200_000, 0]])]);
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('floor_rules'));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('floor_rules'));
        Schema::table('invites', fn (Blueprint $t) => $t->dropConstrainedForeignId('vendor_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('vendor_id'));
    }
};
