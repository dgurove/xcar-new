<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Гараж покупателю по тумблеру (20.10.2026, владелец: «у покупателей и суперпокупателей гаража быть не должно, но в
 * пользователях — тумблер, чтобы мы могли им тачки отдавать»): `users.garage_enabled`; цена, по которой машину отдали
 * покупателю, — `garage_cars.buyer_price` (закупочную он не видит нигде).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('garage_enabled')->default(false));
        Schema::table('garage_cars', fn (Blueprint $t) => $t->unsignedBigInteger('buyer_price')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('garage_enabled'));
        Schema::table('garage_cars', fn (Blueprint $t) => $t->dropColumn('buyer_price'));
    }
};
