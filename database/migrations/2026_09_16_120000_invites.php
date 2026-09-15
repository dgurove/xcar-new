<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пригласительные ссылки менеджера. Многоразовые; код хранится открыто —
 * это не секрет входа, а адрес, который менеджер показывает снова и снова.
 * `fields` — что покупатель указывает при регистрации: {"phone": bool, "email": bool}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->string('label', 60)->nullable();
            $table->jsonb('fields')->default('{}');
            $table->foreignId('group_id')->nullable()->constrained('buyer_groups')->nullOnDelete();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
