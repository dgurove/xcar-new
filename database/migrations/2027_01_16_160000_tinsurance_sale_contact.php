<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Письмо Т-Страхованию по сделке (16.10.2026): у Т адресат — тот, кто прислал машину (каждый раз свой сотрудник, письмо
 * ответом на его), а в копии всех их писем — общий ящик total@tinkoffinsurance.ru. Контакта «Реализация» у Т не было, и
 * сделки без письма от Т (машины из «Заявки» списком номеров) открывали черновик с пустым «Кому». Теперь total@ — в копии
 * всегда, а без письма — адресат (`Vendor::saleAddressee`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('vendors')->where('name', 'Т-Страхование')->pluck('id') as $vendor) {
            if (DB::table('vendor_contacts')->where('vendor_id', $vendor)->whereRaw('lower(email) = ?', ['total@tinkoffinsurance.ru'])->exists()) {
                continue;
            }
            DB::table('vendor_contacts')->insert([
                'vendor_id' => $vendor, 'name' => 'Реализация', 'role' => 'sales', 'email' => 'total@tinkoffinsurance.ru',
                'always_cc' => true, 'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
