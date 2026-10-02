<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблонов волн показа больше нет (02.10.2026, владелец убрал «Кому показывать»): волны ставятся прямо у вендора
 * (`vendors.audience_rules`) и у предложения. Выбранный вендором шаблон переносится ему правилами; шаблоны и метки
 * «какой шаблон применён» удаляются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $table) => $table->jsonb('audience_rules')->nullable());
        DB::statement('update vendors v set audience_rules = a.rules from audiences a where a.id = v.audience_id');
        Schema::table('vendors', fn (Blueprint $table) => $table->dropConstrainedForeignId('audience_id'));
        Schema::table('offers', fn (Blueprint $table) => $table->dropConstrainedForeignId('audience_id'));
        Schema::drop('audiences');
    }

    public function down(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('rules');
            $table->timestamps();
        });
        Schema::table('vendors', fn (Blueprint $table) => $table->foreignId('audience_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('offers', fn (Blueprint $table) => $table->foreignId('audience_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn('audience_rules'));
    }
};
