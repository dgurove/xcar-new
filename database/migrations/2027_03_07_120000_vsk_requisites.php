<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Реквизиты и договор ВСК (05.11.2026, владелец: «может на почте где-то есть?»): в архиве «СТОЯНКА» их нет, нашлись в
 * письме akondratov@vsk.ru от 03.09.2026 — договор № 26-294-Х от 15.07.2026 (Диадок, 16.07.2026). Ставки договора
 * (легковые 120, ЛКТ 150, грузовые 180, длинномеры 200, без НДС) уже стоят с 29.07.2026. Акт — приложение № 3 «Акт
 * выполненных работ» строкой на ТС: форма `works`. Оплата — 10 календарных дней с получения счёта и акта: 8 рабочих.
 * Записанное руками не трогается — только пустое.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendor = DB::table('vendors')->where('name', 'ВСК')->first();
        if (! $vendor) {
            return;
        }
        $party = [
            'name' => 'САО «ВСК»', 'full_name' => 'Страховое акционерное общество «ВСК»', 'inn' => '7710026574', 'kpp' => '997950001', 'ogrn' => '1027700186062',
            'legal_address' => '121552, г. Москва, ул. Островная, д. 4',
            'bank_name' => 'ПАО «Сбербанк России»', 'bik' => '044525225', 'account' => '40701810600020001241', 'corr_account' => '30101810400000000225',
            'director' => 'Пигорев С. В.', 'director_full' => 'Пигорев Сергей Владимирович',
            'signed_by' => 'Руководителя Центра урегулирования убытков по автострахованию Пигорева Сергея Владимировича',
            'director_basis' => 'доверенности № b792d4e2-3087-41db-b468-3d4761673986 от 25.08.2025 г.',
        ];
        $partyId = $vendor->party_id ?: (DB::table('billing_parties')->where('inn', $party['inn'])->value('id')
            ?? DB::table('billing_parties')->insertGetId(['kind' => 'company', 'name' => $party['name'], 'is_self' => false, 'created_at' => now(), 'updated_at' => now()]));
        $current = DB::table('billing_parties')->find($partyId);
        $fill = array_filter($party, fn ($v, $k) => empty($current->{$k}) || ($k === 'name' && $current->name === $vendor->name), ARRAY_FILTER_USE_BOTH);
        if ($fill) {
            DB::table('billing_parties')->where('id', $partyId)->update($fill + ['updated_at' => now()]);
        }
        $own = ['party_id' => $partyId, 'agreement_number' => '26-294-Х', 'agreement_date' => '2026-07-15', 'act_form' => 'works', 'payment_days' => 8];
        DB::table('vendors')->where('id', $vendor->id)->update(array_filter($own, fn ($v, $k) => empty($vendor->{$k}), ARRAY_FILTER_USE_BOTH) + ['updated_at' => now()]);
    }

    public function down(): void {}
};
