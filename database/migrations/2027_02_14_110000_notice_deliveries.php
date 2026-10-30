<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал доставки уведомлений (30.10.2026, владелец: «статистику пушей обычно ведут»): строка — одно уведомление одному
 * человеку по одному каналу, с отметкой, открыли ли его. До того пуши не писались нигде, а лента схлопывается до строки на
 * объект. Экран — «Настройки → Доставка» (`Admin\DeliveryController`), хранится 180 дней (`Delivery::prune`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('notice_id');
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 64);
            $t->string('label', 80);
            $t->string('category', 32);
            $t->boolean('important')->default(false);
            $t->string('channel', 16);
            $t->string('status', 16);
            $t->string('detail', 160)->nullable();
            $t->unsignedSmallInteger('devices')->nullable();
            $t->timestamp('created_at')->index();
            $t->timestamp('opened_at')->nullable();
            $t->string('opened_via', 16)->nullable();
            $t->index(['notice_id', 'user_id']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_deliveries');
    }
};
