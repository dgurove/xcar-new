<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Письмо страховой после «Принять» (08.10.2026, владелец): у Альфы адресат по продаже — implementation@alfastrah.ru, в
 * копии third-party@alfastrah.ru (контакты «Реализация»); в теме уведомления о покупке — только номер убытка, в тексте —
 * «Прошу согласовать реализацию за 756 000 ₽ (+10%)» и подпись Андрея. Шаблон этапа правят и в «Шаблонах» — тут только
 * заготовка, которую не трогали руками.
 */
return new class extends Migration
{
    private const OLD_SUBJECT = 'Покупка {{ car }}, убыток {{ claim_ref }}';

    public function up(): void
    {
        DB::table('mail_templates')
            ->where('scope', 'offers')->where('name', 'Поставщику: уведомление о покупке')->where('subject', self::OLD_SUBJECT)
            ->update([
                'subject' => '{{ claim_ref }}',
                'body' => "Здравствуйте!\n\nСообщаем о готовности приобрести {{ car }}, VIN {{ vin }}, убыток {{ claim_ref }}.\nПрошу согласовать реализацию за {{ floor }}\n\nС уважением,\nАндрей Кузнецов",
                'updated_at' => now(),
            ]);

        foreach (DB::table('vendors')->where('name', 'like', 'АльфаСтрахование%')->pluck('id') as $vendor) {
            foreach ([['Реализация', 'implementation@alfastrah.ru', false, true], ['Третьи лица', 'third-party@alfastrah.ru', true, false]] as [$name, $email, $cc, $default]) {
                if (DB::table('vendor_contacts')->where('vendor_id', $vendor)->whereRaw('lower(email) = ?', [$email])->exists()) {
                    continue;
                }
                DB::table('vendor_contacts')->insert([
                    'vendor_id' => $vendor, 'name' => $name, 'role' => 'sales', 'email' => $email,
                    'always_cc' => $cc, 'is_default' => $default, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void {}
};
