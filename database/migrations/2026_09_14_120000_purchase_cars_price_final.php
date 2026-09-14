<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Наша цена по машине закупки — то, что уходит поставщику в «Предложение клиента». */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_cars', function (Blueprint $table) {
            $table->unsignedInteger('price_final')->nullable()->after('price_listing');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_cars', function (Blueprint $table) {
            $table->dropColumn('price_final');
        });
    }
};
