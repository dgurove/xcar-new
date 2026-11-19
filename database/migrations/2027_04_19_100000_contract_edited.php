<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исправленный договор (19.11.2026, владелец: «либо отправить как есть, либо скачать docx, отредактировать и отправить менеджеру
 * его»): файл — у сделки (`dkp_edited`, его PDF — `dkp_edited_pdf`), здесь — кто и когда приложил и отпечаток сформированного
 * договора на тот момент (`edited_base`): данные сделки поменялись после правки — строка договора скажет об этом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->timestamp('edited_at')->nullable();
            $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('edited_base', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('edited_by');
            $t->dropColumn(['edited_at', 'edited_base']);
        });
    }
};
