<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Вторая сторона чата: пусто — сотрудники площадки, задан — менеджер, с которым говорит его покупатель.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete()->index();
        });
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropConstrainedForeignId('manager_id'));
    }
};
