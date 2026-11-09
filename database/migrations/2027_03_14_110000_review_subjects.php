<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тема письма на сверку — с продавцом (09.11.2026, владелец: «Стоянка ИП Кузнецов А.В. отчет-акт за октябрь 2026 на
 * сверку»). Шаблоны сверки, уже заведённые первым письмом, получают новую тему, если её не правили руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'Сверка: счёт и акт по реестру' => ['Отчет-акт за {{ month }} на сверку', 'Стоянка {{ seller }} счёт и отчет-акт за {{ month }} на сверку'],
            'Сверка: акт по реестру' => ['Отчет-акт за {{ month }} на сверку', 'Стоянка {{ seller }} отчет-акт за {{ month }} на сверку'],
            'Сверка: счёт по реестру' => ['Счёт за {{ month }} на сверку', 'Стоянка {{ seller }} счёт за {{ month }} на сверку'],
        ] as $name => [$old, $new]) {
            DB::table('mail_templates')->where('scope', 'park')->where('name', $name)->where('subject', $old)->update(['subject' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
