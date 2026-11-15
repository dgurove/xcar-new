<?php

use App\Vendors\Vendor;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Письмо о покупке Инсайту — как у Т-Страхования (16.11.2026, владелец про «Выражаем интерес», ушедшие Инсайту: «там вообще
 * другой текст должен быть», «как у Т-Страхования»): «Выражаем готовность к покупке …, VIN … за <закупочная>. Просим прислать
 * контакты и данные для организации сделки». Шаблон «уведомление о покупке» общий с другими маршрутами — у Инсайта шаг
 * просто ведёт на шаблон Т-Страхования «запрос контактов».
 */
return new class extends Migration
{
    public function up(): void
    {
        $template = DB::table('mail_templates')->where('scope', 'offers')->where('name', 'Поставщику: запрос контактов')->value('id');
        $workflow = Vendor::where('name', 'ИНСАЙТ')->first()?->workflow(Track::Sale);
        if ($template && $workflow) {
            Stage::where('workflow_id', $workflow->id)->where('name', 'Письмо о покупке')->update(['template_id' => $template]);
        }
    }

    public function down(): void {}
};
