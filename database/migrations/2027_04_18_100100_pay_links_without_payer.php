<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ссылки на оплату подбора без плательщика (18.11.2026, владелец: «плательщика ваще вырезаем… по ссылке просто почта указывается и
 * всё»). Открытые ссылки, заведённые системой вместе со счётом (`auto`), несли имя и почту стороны счёта — обычно самого менеджера:
 * на `/pay` клиент видел бы его почту в поле «Почта для чека». Снимаем только то, что вписала система (почта = почте стороны);
 * почту, введённую человеком, не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('billing_pay_links as l')
            ->join('billing_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('billing_parties as p', 'p.id', '=', 'i.party_id')
            ->where('l.state', 'open')->where('l.auto', true)->where('i.kind', 'selection')
            ->where(fn ($w) => $w->whereColumn('l.payer_email', 'p.email')->orWhereNull('l.payer_email'))
            ->pluck('l.id');
        DB::table('billing_pay_links')->whereIn('id', $ids)->update(['payer_name' => null, 'payer_email' => null]);
    }

    public function down(): void {}
};
