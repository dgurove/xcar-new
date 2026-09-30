<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вход админа за человека: одноразовая ссылка на 15 минут (в базе хэш), кто за кого, когда вошёл и вышел,
 * откуда. Каждая запись за него (не GET) — строкой в impersonation_actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('impersonation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonation_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('status')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_actions');
        Schema::dropIfExists('impersonations');
    }
};
