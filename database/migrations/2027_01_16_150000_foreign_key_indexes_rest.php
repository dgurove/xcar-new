<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под остальные внешние ключи (16.10.2026): Postgres их сам не ставит, а 12.01 (`foreign_key_indexes`) закрыли только
 * почту, события ТС и цепочки. Без индекса по связи — полный проход: заявки ТС (`park_requests.vehicle_id`) на каждом деле,
 * письма ветки, удаление человека проверяет каждую таблицу, где он автор. Список — запросом к pg_catalog: связь из одного
 * столбца без индекса, который начинается с него.
 *
 * `concurrently` — без замка на запись, поэтому вне транзакции. Прерванная сборка оставляет индекс невалидным, и `if not
 * exists` его бы пропустил — такой сначала снимается.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'app_settings' => ['updated_by'],
        'bids' => ['decided_by', 'placed_by'],
        'billing_acquiring_payments' => ['link_id', 'payment_id', 'payout_tx_id'],
        'billing_bank_connections' => ['connected_by'],
        'billing_bank_transactions' => ['decided_by', 'invoice_id', 'payment_id'],
        'billing_charges' => ['created_by', 'deal_id', 'invoice_id', 'party_id'],
        'billing_invoices' => ['created_by', 'offer_id', 'vehicle_id'],
        'billing_pay_links' => ['created_by', 'payer_user_id', 'payment_id'],
        'billing_payments' => ['created_by', 'party_id'],
        'cars' => ['brand_id', 'model_id'],
        'chat_files' => ['message_id'],
        'chat_messages' => ['author_id'],
        'deal_contracts' => ['buyer_user_id', 'seller_party_id'],
        'deals' => ['bid_id'],
        'garage_cars' => ['created_by', 'deal_id', 'invoice_id', 'payout_invoice_id'],
        'garage_costs' => ['created_by'],
        'impersonation_actions' => ['impersonation_id'],
        'impersonations' => ['admin_id', 'user_id'],
        'invites' => ['created_by', 'group_id', 'manager_id', 'park_city_id', 'user_group_id'],
        'legacy_claims' => ['added_by'],
        'mail_accounts' => ['reply_account_id'],
        'mail_candidates' => ['message_id', 'vendor_id'],
        'mail_messages' => ['created_by'],
        'mail_threads' => ['vendor_id'],
        'offer_bot_chats' => ['offer_id'],
        'offer_bot_seen' => ['offer_id'],
        'offer_estimates' => ['asked_by'],
        'offer_events' => ['user_id'],
        'offer_number_aliases' => ['offer_id'],
        'offer_positions' => ['stage_id'],
        'offers' => ['auction_by', 'moderator_id', 'settlement_id', 'slot_by', 'urgent_by', 'vendor_id'],
        'park_inspections' => ['request_id', 'user_id'],
        'park_passes' => ['confirmed_by', 'request_message_id', 'used_by'],
        'park_requests' => ['assignee_id', 'created_by', 'done_by', 'letter_id', 'thread_id', 'vehicle_id', 'yard_id'],
        'park_tariffs' => ['yard_id'],
        'park_vehicle_docs' => ['media_id', 'thread_id', 'user_id'],
        'park_vehicle_events' => ['user_id'],
        'park_vehicles' => ['buyer_party_id', 'owner_party_id', 'sold_message_id', 'vendor_id', 'yard_id'],
        'park_yards' => ['settlement_id'],
        'password_links' => ['created_by', 'user_id'],
        'purchase_cars' => ['car_id', 'settlement_id'],
        'purchases' => ['imported_by', 'vendor_id'],
        'push_subscriptions' => ['user_id'],
        'requirements' => ['offer_id', 'stage_id'],
        'tag_vendor' => ['vendor_id'],
        'telegram_chats' => ['user_id'],
        'telegram_files' => ['media_id'],
        'telegram_messages' => ['author_id'],
        'transports' => ['created_by', 'executor_id', 'from_region_id', 'garage_cost_id', 'invoice_id', 'to_region_id', 'to_yard_id'],
        'users' => ['approved_by', 'invite_id', 'park_city_id', 'party_id'],
        'vendor_contacts' => ['vendor_id', 'yard_id'],
        'vendors' => ['crm_vendor_id', 'home_yard_id', 'mail_account_id', 'party_id', 'refusal_template_id', 'report_template_id'],
        'vin_facts' => ['brand_id', 'model_id'],
        'workflow_blocks' => ['workflow_id'],
        'workflow_exits' => ['stage_id', 'to_stage_id'],
        'workflow_stages' => ['block_id', 'template_id', 'workflow_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $name = "{$table}_{$column}_fk_index";
                $invalid = DB::scalar('select count(*) from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = ? and not i.indisvalid', [$name]);
                if ($invalid) {
                    DB::statement("drop index concurrently if exists {$name}");
                }
                DB::statement("create index concurrently if not exists {$name} on {$table} ({$column})");
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("drop index concurrently if exists {$table}_{$column}_fk_index");
            }
        }
    }
};
