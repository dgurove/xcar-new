<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Что видит покупатель менеджера (12.10.2026, владелец: «неразбериха с покупателями», покупатели спрашивают цену —
 * у 5 из 8 не было открыто ничего): `all` — всё, что открыто его менеджеру, сразу с ценой продажи; `picked` — только
 * открытое менеджером вручную (прежнее устройство). По умолчанию — всё; тем, кому менеджер уже что-то открывал, остаётся
 * выбор. VIN в режиме «все» — галкой у покупателя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('buyer_sees', 8)->default('all');
            $t->boolean('buyer_show_vin')->default(false);
        });
        Schema::table('invites', function (Blueprint $t) {
            $t->string('buyer_sees', 8)->default('all');
        });

        DB::table('users')->whereIn('id', DB::table('showings')->whereNotNull('user_id')->select('user_id'))
            ->orWhereIn('id', DB::table('buyer_group_user')->whereIn('group_id', DB::table('showings')->whereNotNull('group_id')->select('group_id'))->select('user_id'))
            ->update(['buyer_sees' => 'picked']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['buyer_sees', 'buyer_show_vin']));
        Schema::table('invites', fn (Blueprint $t) => $t->dropColumn('buyer_sees'));
    }
};
