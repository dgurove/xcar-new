<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ответили ли поставщику на письмо переоценки (04.11.2026, владелец: «пришло сообщение от страховой, и мы ей так и не
 * ответили — приняли мы новую цену или нет»): когда и кто. Ставит отправка ответа на письмо или «Ответили не письмом».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_reprices', function (Blueprint $table) {
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offer_reprices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn('answered_at');
        });
    }
};
