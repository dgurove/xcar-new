<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Вся история «Счетов и актов» — живыми документами (05.11.2026, владелец: «у нас за всю историю есть данные, можно за всю
 * историю счета и акты составлять; делай так же, как свежие, с такими же кнопками — сверю со старыми актами и прожму, что
 * отправлено и что оплачено»). Стартовая точка архива (`2027_03_05_110000`: СОГАЗ по 31.08.2026, Альфа Москва — выданные
 * до сентября) снимается: «выставлено по» остаётся только у ТС, чьё хранение есть в неаннулированном счёте.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('park_vehicles')->whereNotNull('storage_billed_until')
            ->whereNotExists(fn ($q) => $q->from('billing_charges as c')->join('billing_invoices as i', 'i.id', '=', 'c.invoice_id')
                ->whereColumn('c.vehicle_id', 'park_vehicles.id')->where('c.kind', 'storage')->whereNull('c.voided_at')->where('i.state', '!=', 'void'))
            ->update(['storage_billed_until' => null]);
    }

    public function down(): void {}
};
