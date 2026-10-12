<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы на связи (12.10.2026): у вложений, адресов, веток, событий ТС и цепочек не было индекса по внешнему ключу — каждое
 * «письма с вложениями» читало таблицу целиком (`mail_attachments` — 113 тыс. строк, 74 полных прохода за 20 с, Postgres
 * держал полпроцессора). Внешние связи вернули 24.08.2026, а индексы под ними Postgres сам не ставит.
 */
return new class extends Migration
{
    private const INDEXES = [
        'mail_attachments' => ['message_id'],
        'mail_addresses' => ['message_id'],
        'mail_threads' => ['offer_id', 'candidate_id', 'account_id'],
        'park_vehicle_events' => ['vehicle_id'],
        'mail_candidate_messages' => ['message_id'],
        'mail_candidates' => ['offer_id', 'vehicle_id', 'thread_id'],
        'migtorg_lots' => ['offer_id'],
        'purchase_cars' => ['offer_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::statement("create index if not exists {$table}_{$column}_fk_index on {$table} ({$column})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("drop index if exists {$table}_{$column}_fk_index");
            }
        }
    }
};
