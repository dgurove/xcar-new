<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Уведомление о покупке (08.10.2026, владелец): «Выражаем интерес в покупке … (убыток) за 756 000 ₽ (+10%)» одной фразой. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_templates')->where('scope', 'offers')->where('name', 'Поставщику: уведомление о покупке')
            ->where('body', "Здравствуйте!\n\nСообщаем о готовности приобрести {{ car }}, VIN {{ vin }}, убыток {{ claim_ref }}.\nПрошу согласовать реализацию за {{ floor }}\n\nС уважением,\nАндрей Кузнецов")
            ->update(['body' => "Здравствуйте!\n\nВыражаем интерес в покупке {{ car }}, VIN {{ vin }} ({{ claim_ref }}) за {{ floor }}\n\nС уважением,\nАндрей Кузнецов", 'updated_at' => now()]);
    }

    public function down(): void {}
};
