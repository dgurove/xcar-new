<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Показ всем менеджерам, пока менеджер сделки думает о новой закупочной (04.11.2026, владелец: «пока он думает — опубликовать её
 * снова в предложениях для всех менеджеров на неделю, согласится — сразу убрать»): с какого момента. Срок — `deal_show_until`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('deal_show_all_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('deal_show_all_at');
        });
    }
};
