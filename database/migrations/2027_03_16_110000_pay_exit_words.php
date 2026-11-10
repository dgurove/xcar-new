<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Платёжное поручение приложено» → «Оплатил, документ приложен» (10.11.2026, владелец: первая оплата по счёту Альфы — чек
 * кассы, а не платёжка; бывает скан или файл). Письмо поставщику — «Документ об оплате во вложении» (PDF, `PaymentProofPdf`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_exits')->where('label', 'Платёжное поручение приложено')->update(['label' => 'Оплатил, документ приложен']);
        foreach (DB::table('mail_templates')->where('body', 'like', '%Платёжное поручение во вложении%')->get() as $t) {
            DB::table('mail_templates')->where('id', $t->id)->update([
                'name' => str_replace('платёжное поручение', 'документ об оплате', $t->name),
                'body' => str_replace('Платёжное поручение во вложении', 'Документ об оплате во вложении', $t->body),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_exits')->where('label', 'Оплатил, документ приложен')->update(['label' => 'Платёжное поручение приложено']);
    }
};
