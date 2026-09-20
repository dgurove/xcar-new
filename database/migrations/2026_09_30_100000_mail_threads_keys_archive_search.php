<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Почта как почта: у ветки — её номера (`keys`: code:/vin:/plate: по всем письмам, чтобы «ч.2» и наши ответы
 * садились под кандидата и ТС), кандидат (`candidate_id`) и архив (`archived_at`); у письма — полнотекстовый
 * индекс `search` (тема, текст, отправитель) для поиска по всей почте.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->jsonb('keys')->default('[]');
            $t->foreignId('candidate_id')->nullable()->constrained('mail_candidates')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
        });
        DB::statement('create index mail_threads_keys_index on mail_threads using gin (keys)');
        // Лимит tsvector — 1 МБ, поэтому текст письма режется.
        DB::statement("alter table mail_messages add column search tsvector generated always as (to_tsvector('russian', left(coalesce(subject, ''), 1000) || ' ' || left(coalesce(text_body, ''), 200000) || ' ' || coalesce(from_name, '') || ' ' || coalesce(from_email, ''))) stored");
        DB::statement('create index mail_messages_search_index on mail_messages using gin (search)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists mail_messages_search_index');
        DB::statement('alter table mail_messages drop column if exists search');
        DB::statement('drop index if exists mail_threads_keys_index');
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->dropConstrainedForeignId('candidate_id');
            $t->dropColumn(['keys', 'archived_at']);
        });
    }
};
