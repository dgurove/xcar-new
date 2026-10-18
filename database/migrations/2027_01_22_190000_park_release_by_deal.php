<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выдача с нашей парковки по сделке XCar (18.10.2026, владелец): кто заберёт — решает менеджер в сделке, на воротах —
 * QR-пропуск, подтверждённый самой сделкой; заявка на выдачу помнит сделку (источник строки — сделка, а не письмо).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->foreignId('receiver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('receiver_name', 160)->nullable();
            $t->string('receiver_phone', 32)->nullable();
            $t->timestamp('receiver_at')->nullable();
        });
        Schema::table('park_passes', function (Blueprint $t) {
            $t->foreignId('deal_id')->nullable()->index()->constrained('deals')->nullOnDelete();
            $t->timestamp('planned_at')->nullable();
            // У пропуска сделки анкеты нет: почты и дня может не быть (время — `planned_at`).
            $t->string('email', 160)->nullable()->change();
            $t->date('pickup_on')->nullable()->change();
        });
        Schema::table('park_requests', function (Blueprint $t) {
            $t->foreignId('deal_id')->nullable()->index()->constrained('deals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('park_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('deal_id'));
        Schema::table('park_passes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deal_id');
            $t->dropColumn('planned_at');
        });
        Schema::table('deals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('receiver_user_id');
            $t->dropColumn(['receiver_name', 'receiver_phone', 'receiver_at']);
        });
    }
};
