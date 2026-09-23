<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Поиск по письмам ищет по ходу набора, и две его ветки шли последовательным проходом: участники ветки
 * (`participants::text ilike '%…%'`) и имена вложений. Триграммный индекс делает такой `ilike` быстрым —
 * ведущая звёздочка обычному индексу не по зубам. Полнотекст и номера уже под GIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create index if not exists mail_threads_participants_trgm on mail_threads using gin ((participants::text) gin_trgm_ops)');
        DB::statement('create index if not exists mail_attachments_filename_trgm on mail_attachments using gin (filename gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists mail_threads_participants_trgm');
        DB::statement('drop index if exists mail_attachments_filename_trgm');
    }
};
