<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто удалил сообщение чата (17.11.2026, владелец: удаление в чате CRM — админ удаляет любое, у менеджера оно исчезает бесследно,
 * сотрудники видят серым «Удалено: кто, когда»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('deleted_by'));
    }
};
