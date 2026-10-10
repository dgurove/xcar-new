<?php

use App\Workflow\Stage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Догонка к `tbank_no_manager_confirm` (ревью 10.10.2026): просьбы убранных этапов («Согласие менеджера», «Подтвердили
 * покупку поставщику») переехали на «Контакты владельца переданы менеджеру» — в блок «Получение автомобиля», и путь в CRM
 * показывал там «Менеджер: Покупаю» и «Подтвердите, что берёте в работу», хотя менеджер там ничего не нажимал. Возвращаем
 * их на «Уведомили поставщика о покупке» — тот же блок «Согласование», где их и отвечали.
 *
 * Заодно текст «Согласия менеджера» — как в пресете: миграция 06 оставила «…или откажитесь от неё», а кнопка там «Я
 * передумал»; открытые просьбы — тот же текст (у гаражной — без «по Вашей цене», как пишет `Requirement::askFor`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Stage::where('name', 'Контакты владельца переданы менеджеру')->get() as $contacts) {
            $claimed = Stage::where('workflow_id', $contacts->workflow_id)->where('name', 'Уведомили поставщика о покупке')->first();
            if (! $claimed) {
                continue;
            }
            DB::table('requirements')->where('stage_id', $contacts->id)
                ->whereIn('title', ['Подтвердите покупку', 'Подтвердите, что берёте в работу', 'Возьмите сделку в работу'])
                ->update(['stage_id' => $claimed->id]);
        }

        $text = 'Поставщик согласовал продажу автомобиля по Вашей цене. Подтвердите, что берёте сделку в работу';
        $ids = Stage::where('name', 'Согласие менеджера')->pluck('id');
        DB::table('workflow_stages')->whereIn('id', $ids)->update(['ask_title' => 'Подтвердите, что берёте в работу', 'ask_text' => $text]);
        DB::table('requirements')->whereIn('stage_id', $ids)->whereNull('done_at')->update(['title' => 'Подтвердите, что берёте в работу',
            'text' => DB::raw("case when exists (select 1 from deals d where d.id = requirements.deal_id and d.garage_payer is not null) then '".str_replace(' по Вашей цене', '', $text)."' else '".$text."' end")]);
    }

    public function down(): void {}
};
