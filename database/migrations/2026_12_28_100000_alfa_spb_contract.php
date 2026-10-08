<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Альфа СПб по питерскому отчёт-акту (владелец 09.10.2026): договор № 01/С/24 от 10.04.2024, юрлицо АО
 * «АльфаСтрахование» (ИНН, КПП, адрес — открытые данные головной компании; банка в наших письмах и актах нет — его
 * впишет управляющий в «Реквизитах»), грузовые, тягачи и прицепы — 250 ₽ в сутки, как в акте (легковые остаются
 * 120 ₽), счета и отчёт-акты — Колбасиной (контакт «Бухгалтерия»: ей Питер слал отчёт-акты и счета).
 */
return new class extends Migration
{
    public function up(): void
    {
        $alfa = DB::table('vendors')->where('name', 'АльфаСтрахование СПб')->first();
        if (! $alfa) {
            return;
        }
        $fill = array_filter([
            'agreement_number' => '01/С/24', 'agreement_date' => '2024-04-10',
            'legal_name' => 'АО «АльфаСтрахование»', 'inn' => '7713056834', 'kpp' => '772501001',
            'legal_address' => '115162, г. Москва, ул. Шаболовка, д. 31, стр. Б',
        ], fn ($v, $k) => empty($alfa->{$k}), ARRAY_FILTER_USE_BOTH);
        if ($fill) {
            DB::table('vendors')->where('id', $alfa->id)->update($fill + ['updated_at' => now()]);
        }

        foreach (['truck', 'long', 'trailer'] as $category) {
            $row = DB::table('park_tariffs')->where('vendor_id', $alfa->id)->whereNull('yard_id')->where('category', $category)->where('service', 'storage')->whereNull('valid_to');
            if ($row->exists()) {
                $row->update(['price' => 250, 'updated_at' => now()]);
            } else {
                DB::table('park_tariffs')->insert(['vendor_id' => $alfa->id, 'yard_id' => null, 'category' => $category, 'service' => 'storage', 'from_day' => 1,
                    'price' => 250, 'vat' => false, 'valid_from' => '2025-01-01', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (! DB::table('vendor_contacts')->where('vendor_id', $alfa->id)->where('role', 'accounting')->exists()) {
            DB::table('vendor_contacts')->insert(['vendor_id' => $alfa->id, 'name' => 'Колбасина Мария Петровна', 'role' => 'accounting',
                'email' => 'kolbasinamp@alfastrah.ru', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
