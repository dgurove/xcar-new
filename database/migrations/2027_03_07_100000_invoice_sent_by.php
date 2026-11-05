<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто и как отправил документ (05.11.2026, «Счета и акты»: история в карточке — «Отправлен письмом на …» или «Отмечен
 * отправленным, Иван Петров»). Письмо — `sent_message_id`, без него — отметили сами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->foreignId('sent_by')->nullable()->after('sent_at')->constrained('users')->nullOnDelete();
            $t->foreignId('sent_message_id')->nullable()->after('sent_by')->constrained('mail_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sent_message_id');
            $t->dropConstrainedForeignId('sent_by');
        });
    }
};
