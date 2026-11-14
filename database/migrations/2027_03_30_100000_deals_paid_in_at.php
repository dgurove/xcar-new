<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Продано» — когда нам за машину заплатили (14.11.2026, владелец: во «Все» столбцы «Продано» и «Оплачено» с датами, правка
 * нажатием, сортировка). Ставит `StampPaidIn`, когда наши счета сделки оплачены целиком, правит админ (`SetDealDate`).
 * Задним числом — последняя оплата целиком оплаченных счетов. Мимо нас (наших счетов нет) — `prepaid_at` (`DealDates`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('paid_in_at')->nullable());
        DB::statement(<<<'SQL'
            update deals d set paid_in_at = p.at
            from (
                select i.deal_id, max(pm.paid_at)::timestamp + interval '12 hours' as at
                from billing_invoices i join billing_payments pm on pm.invoice_id = i.id and pm.state = 'confirmed'
                where i.direction = 'issued' and i.state <> 'void' and i.deal_id is not null and i.kind <> 'reward'
                group by i.deal_id
                having bool_and(i.state = 'paid')
            ) p
            where p.deal_id = d.id and d.state in ('active', 'done')
            SQL);
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('paid_in_at'));
    }
};
