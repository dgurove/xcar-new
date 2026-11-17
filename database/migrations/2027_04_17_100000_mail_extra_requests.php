<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доп. запрос поставщику (17.11.2026, владелец по CTTM Cargoline 5491/046/00861/26: «мы действительно часто запрашиваем ПТС
 * и согласование осмотра перед подтверждением актуальности, просто в системе нет такой функции»): шаблон вида `request` —
 * галка в окне письма (`name`) и фраза после «Просьба …» (`body`), порядок фраз — `position`, варианты `{{ city }}` —
 * `choices`, `attach = payment` — PDF оплаты во вложении. Что спросили — `mail_messages.requests` (словами на момент отправки).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->string('kind', 8)->default('letter');
            $table->unsignedSmallInteger('position')->nullable();
            $table->jsonb('choices')->nullable();
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->jsonb('requests')->nullable();
        });

        $now = now();
        $rows = [
            ['Запросить ПТС', 'прислать ПТС'],
            ['Справка о закрытии лизинга', 'прислать справку о закрытии лизинга'],
            ['Доп. фото с приёма', 'прислать дополнительные фотографии с приёма'],
            ['Контакты филиала для вывоза', 'прислать контакты филиала для вывоза'],
            ['Готовность ДКП', 'сообщить о готовности ДКП'],
            ['Согласовать осмотр', 'согласовать осмотр'],
            ['Снять залог', 'снять залог'],
            ['Ограничения рег. действий', 'снять ограничения на регистрационные действия'],
            ['Подписание в городе', 'записать на подписание документов {{ city }}', ['в Москве', 'в Санкт-Петербурге', 'в Екатеринбурге']],
            ['Подтвердить оплату', 'подтвердить поступление оплаты', null, 'payment'],
        ];
        foreach ($rows as $i => $row) {
            [$name, $body] = $row;
            if (DB::table('mail_templates')->where('scope', 'offers')->where('kind', 'request')->where('name', $name)->exists()) {
                continue;
            }
            DB::table('mail_templates')->insert(['scope' => 'offers', 'kind' => 'request', 'name' => $name, 'subject' => null, 'body' => $body,
                'position' => $i + 1, 'choices' => isset($row[2]) ? json_encode($row[2], JSON_UNESCAPED_UNICODE) : null, 'attach' => $row[3] ?? null,
                'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('mail_templates')->where('kind', 'request')->delete();
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->dropColumn(['kind', 'position', 'choices']);
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn('requests');
        });
    }
};
