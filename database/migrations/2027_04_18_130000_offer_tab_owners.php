<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ответственные за вкладки «Предложений» (18.11.2026, владелец: «в настройках у админов раздел „Ответственные“: список всех
 * вкладок, у каждой — выбор из сотрудников, можно несколько»; «Только мои» в колесе оставляет вкладки человека).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_tab_owners', function (Blueprint $t) {
            $t->id();
            $t->string('preset', 40);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['preset', 'user_id']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_tab_owners');
    }
};
