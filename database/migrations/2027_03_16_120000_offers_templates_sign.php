<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Подпись «С уважением, Андрей Кузнецов» в письмах поставщику, где её нет (10.11.2026, владелец: «в некоторых шаблонах очень не
 * хватает»): «Поставщику: документ об оплате». Шаблоны парковки — без подписи, как писал Сергей (06.11.2026), их не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('mail_templates')->where('scope', 'offers')->where('body', 'not like', '%С уважением%')->get() as $t) {
            DB::table('mail_templates')->where('id', $t->id)->update(['body' => rtrim((string) $t->body)."\n\nС уважением,\nАндрей Кузнецов"]);
        }
    }

    public function down(): void {}
};
