<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Запретить шеринг» у машины закупки — как у оффера. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_cars', function (Blueprint $table) {
            $table->boolean('share_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_cars', function (Blueprint $table) {
            $table->dropColumn('share_locked');
        });
    }
};
