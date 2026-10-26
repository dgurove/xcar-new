<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Черновики с Мигторга сами (26.10.2026, владелец: модераторы открывали все лоты вкладками и заводили Альфу и
 * Т-Страхование по номеру руками): галка вендора «Черновики с Мигторга» и отметка лота, что черновик по нему уже заводили —
 * удалённый человеком черновик сам не возвращается (`offer_id` при удалении обнуляется, отметка остаётся).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->boolean('drafts_from_migtorg')->default(false));
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->timestamp('drafted_at')->nullable());
        DB::table('vendors')->whereIn('name', ['Т-Страхование', 'АльфаСтрахование'])->update(['drafts_from_migtorg' => true]);
        // Лоты, по которым предложение уже было (взяли фото), — не новые.
        DB::table('migtorg_lots')->whereNotNull('offer_id')->update(['drafted_at' => DB::raw('seen_at')]);
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('drafts_from_migtorg'));
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->dropColumn('drafted_at'));
    }
};
