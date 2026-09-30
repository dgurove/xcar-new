<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индексы под экраны сайта (01.10.2026). Postgres сам внешние ключи не индексирует: `foreignId()->constrained()`
 * даёт связь, но не индекс — счётчики таб-бара, «Сделки», «Покупатели», «Деньги», чаты и лента уведомлений
 * читали таблицы целиком. CONCURRENTLY — таблицы на проде не блокируются, поэтому без транзакции.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'bids_user_id_state_index' => 'bids (user_id, state)',
        'deals_buyer_id_index' => 'deals (buyer_id)',
        'interests_user_id_index' => 'interests (user_id)',
        'users_manager_id_index' => 'users (manager_id)',
        'chats_user_id_index' => 'chats (user_id)',
        'chats_manager_id_index' => 'chats (manager_id)',
        'favorites_offer_id_index' => 'favorites (offer_id)',
        'notifications_feed_index' => 'notifications (notifiable_id, created_at desc)',
        'notifications_unread_index' => 'notifications (notifiable_id) where read_at is null',
        'billing_invoices_deal_id_index' => 'billing_invoices (deal_id)',
        'billing_invoices_party_id_index' => 'billing_invoices (party_id)',
        'billing_payments_invoice_id_index' => 'billing_payments (invoice_id)',
        'requirements_deal_id_index' => 'requirements (deal_id)',
        'offer_events_offer_id_type_index' => 'offer_events (offer_id, type)',
        'purchase_offers_user_id_index' => 'purchase_offers (user_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $on) {
            DB::statement("create index concurrently if not exists {$name} on {$on}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("drop index concurrently if exists {$name}");
        }
    }
};
