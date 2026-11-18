<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Его шаги — короче, словами владельца (18.11.2026: «у него горит „Нужны реквизиты“», «Приложите договор»): заголовки этапов
 * для менеджера и открытых просьб. Закрытые просьбы не трогаем — путь берёт нынешний заголовок этапа (`Offers\Turn::path`).
 */
return new class extends Migration
{
    private const WORDS = [
        'Заполнить данные покупателя' => 'Нужны реквизиты',
        'Приложить подписанный договор' => 'Приложить договор',
    ];

    public function up(): void
    {
        foreach (self::WORDS as $was => $now) {
            DB::table('workflow_stages')->where('ask_title', $was)->update(['ask_title' => $now]);
            DB::table('requirements')->whereNull('done_at')->where('title', $was)->update(['title' => $now]);
        }
    }

    public function down(): void
    {
        foreach (self::WORDS as $was => $now) {
            DB::table('workflow_stages')->where('ask_title', $now)->update(['ask_title' => $was]);
            DB::table('requirements')->whereNull('done_at')->where('title', $now)->update(['title' => $was]);
        }
    }
};
