<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личная заметка к ТС на xcar.ru (18.10.2026, владелец: «менеджеры очень просят»): у каждого своя, видна только ему.
 * Пара — ключ; пустую строку не храним.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_notes', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('offer_id')->index()->constrained()->cascadeOnDelete();
            $t->text('text');
            $t->timestamp('updated_at');
            $t->primary(['user_id', 'offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_notes');
    }
};
