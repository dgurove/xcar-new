<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Повторное подтверждение другой ценой помечало прежнее «Отозвано» (07.10.2026: у Макарова в «Не состоялись» висели
 * 680 000 и 600 000 рядом с живыми 630 000 и 580 000). Теперь это `replaced`; прежние замены — те `withdrawn`, у которых
 * в ту же секунду появилось новое подтверждение того же менеджера по тому же предложению.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE bids w SET state = 'replaced'
            WHERE w.state = 'withdrawn' AND EXISTS (
                SELECT 1 FROM bids n WHERE n.offer_id = w.offer_id AND n.user_id = w.user_id AND n.id > w.id
                    AND abs(extract(epoch FROM n.created_at - w.updated_at)) < 2)
            SQL);
    }
};
