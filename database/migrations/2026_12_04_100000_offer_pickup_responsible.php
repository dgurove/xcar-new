<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вывоз с ответственным (04.10.2026): кто вывозит (пусто — мы) и куда — к менеджеру, к нам вне парковки или на парковку
 * (пусто — на парковку, как было). Это не гараж: предложение при вывозе живёт обычной жизнью.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('evacuator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('evacuation_to', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evacuator_id');
            $table->dropColumn('evacuation_to');
        });
    }
};
