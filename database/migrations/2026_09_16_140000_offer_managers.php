<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Круг менеджеров у предложения: по умолчанию все, админ может сузить до списка. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('managers_limited')->default(false);
        });

        Schema::create('offer_managers', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['offer_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_managers');
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('managers_limited');
        });
    }
};
