<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Готовые ответы парковки (02.11.2026, владелец: «выгрузи письма, посмотри, как он обычно пишет»): тексты — самые частые
 * ответы управляющего из «Почты Mail.ru» за 2025–2026 годы. `intent` — на какое письмо предлагать первым, `attach` —
 * какие акт и кадры приложить сами (`intake` | `release`).
 */
return new class extends Migration
{
    private const PHONE = '8 495 669-11-11';

    public function up(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->string('intent', 20)->nullable();
            $table->string('attach', 8)->nullable();
        });

        $now = now();
        $rows = [
            ['Фотоотчёт по приёму', 'docs', 'intake', 'Добрый день. Направляю Вам фотоотчёт и скан-копии документов по принятому ТС.'],
            ['Подписанный АПП', null, 'release', 'Добрый день. Направляю Вам подписанный АПП.'],
            ['Закрывающие документы', null, 'release', 'Добрый день. Направляю Вам закрывающие документы по выданному ТС.'],
            ['Приём согласован', 'intake', null, 'Добрый день. Приём ТС согласован на '],
            ['Адрес стоянки', 'inspect', null, 'Добрый день. ТС находится на стоянке по адресу: {{ address }}. Перед выездом необходимо связаться по номеру телефона: '.self::PHONE],
            ['Связаться со стоянкой', null, null, 'Добрый день. Просьба связаться с представителем стоянки по номеру телефона: '.self::PHONE],
        ];
        foreach ($rows as [$name, $intent, $attach, $body]) {
            if (! DB::table('mail_templates')->where('scope', 'park')->where('name', $name)->exists()) {
                DB::table('mail_templates')->insert(['scope' => 'park', 'name' => $name, 'subject' => null, 'body' => $body,
                    'intent' => $intent, 'attach' => $attach, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->dropColumn(['intent', 'attach']);
        });
    }
};
