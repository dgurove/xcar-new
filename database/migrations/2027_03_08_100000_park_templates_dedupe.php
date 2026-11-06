<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Шаблоны парковки (06.11.2026, владелец: «шаблоны задвоились»; «из шаблона выдачи убрать количество дней»; «фото к письму о
 * выдаче не нужны»):
 * - «Приём на стоянку» и «Выдача со стоянки» — копии 19.09.2026, оставшиеся от переименования в «…с парковки»: код их не
 *   берёт, а в «Шаблонах» они стояли второй раз. Удаляются, если на них ничего не ссылается.
 * - Текст «Выдачи с парковки» — как Сергей писал руками: «Направляю Вам закрывающие документы по выданному ТС.» (без
 *   приветствия, суток и подписи); правленый руками не трогаем.
 * - Готовые ответы с «Акт и фото выдачи» больше ничего сами не прикладывают.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('mail_templates')->where('scope', 'park')->whereIn('name', ['Приём на стоянку', 'Выдача со стоянки'])->pluck('id');
        $used = DB::table('workflow_stages')->whereIn('template_id', $old)->pluck('template_id')
            ->merge(DB::table('vendors')->whereIn('report_template_id', $old)->pluck('report_template_id'))
            ->merge(DB::table('vendors')->whereIn('refusal_template_id', $old)->pluck('refusal_template_id'))->unique()->all();
        DB::table('mail_templates')->whereIn('id', $old->diff($used))->delete();

        // Заготовку (не правленую руками) — текстом, каким Сергей писал сам: без приветствия, суток и «С уважением, ООО «ПРАЙМ»».
        DB::table('mail_templates')->where('scope', 'park')->where('name', 'Выдача с парковки')
            ->where('body', 'like', 'Добрый день.%выдано {{ date }}%С уважением,%ООО «ПРАЙМ»')
            ->update(['body' => 'Направляю Вам закрывающие документы по выданному ТС.', 'updated_at' => now()]);

        DB::table('mail_templates')->where('scope', 'park')->where('attach', 'release')->update(['attach' => null, 'updated_at' => now()]);
    }

    public function down(): void {}
};
