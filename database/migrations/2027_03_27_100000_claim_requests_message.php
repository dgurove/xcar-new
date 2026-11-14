<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо «Согласование реализации», которым ушёл запрос номеров (14.11.2026, владелец: Т-Страхованию — из почты CRM, с
 * deal@, а не Андрею на пересылку): ушло ли, повтор упавшего (`MailClaimDigest::retry`), ответ — в той же ветке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_requests', fn (Blueprint $t) => $t->foreignId('message_id')->nullable()->constrained('mail_messages')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('claim_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('message_id'));
    }
};
