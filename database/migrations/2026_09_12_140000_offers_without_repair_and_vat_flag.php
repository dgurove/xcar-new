<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Поля «Ремонт» нет; «с НДС» — галочка, по умолчанию без НДС. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offers')->whereNull('prices_include_vat')->update(['prices_include_vat' => false]);
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('repair_estimate');
            $table->boolean('prices_include_vat')->default(false)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedInteger('repair_estimate')->nullable();
            $table->boolean('prices_include_vat')->nullable()->default(null)->change();
        });
    }
};
