<?php

use App\Workflow\Presets\Alfa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Этапы маршрутов — задачей, а не прошедшим событием (04.11.2026, владелец: «„Счёт от поставщика“ — будто ждём счёт от
 * Альфы, а это мы им должны отправить реквизиты»; во «Ждём менеджера» не видно, чего ждём). Имя этапа теперь — подпункт
 * шага в пути и «что ждём» в строке «Сделок»: «Уведомили поставщика о покупке» → «Письмо о покупке», «Данные покупателя
 * для счёта» → «Нет реквизитов», блок «Счёт от поставщика» → «Реквизиты для счёта». Правится только прежняя подпись:
 * переименованное руками остаётся. Журнал пути (`car_events` `stage_entered`) — на новые имена: `Path::ladder` узнаёт
 * пройденные блоки по имени.
 */
return new class extends Migration
{
    private const STAGES = [
        'Уведомили поставщика о покупке' => 'Письмо о покупке',
        'Подтвердили покупку поставщику' => 'Подтверждение покупки',
        'Подтвердили покупку поставщику, в гараж' => 'Подтверждение покупки, в гараж',
        'Счёт выставлен менеджеру' => 'Оплата счёта',
        'Данные покупателя для счёта' => 'Нет реквизитов',
        'Реквизиты поставщику' => 'Письмо с реквизитами',
        'Реквизиты поставщику, в гараж' => 'Письмо с реквизитами, в гараж',
        'Оплата по счёту ПРАЙМ' => 'Оплата счёта ПРАЙМ',
        'Подтверждение оплаты поставщиком' => 'Подтверждение оплаты',
        'Контакты владельца переданы менеджеру' => 'Связаться с владельцем',
        'Менеджер забирает автомобиль' => 'Забрать автомобиль',
        'Выдача у поставщика' => 'Забрать у поставщика',
    ];

    private const BLOCK = ['Счёт от поставщика', 'Поставщик выставит счёт по данным покупателя'];

    public function up(): void
    {
        $this->rename(self::STAGES, [self::BLOCK[0] => Alfa::REQUISITES_BLOCK['name']], [self::BLOCK[1] => Alfa::REQUISITES_BLOCK['text']]);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::STAGES), [Alfa::REQUISITES_BLOCK['name'] => self::BLOCK[0]], [Alfa::REQUISITES_BLOCK['text'] => self::BLOCK[1]]);
    }

    private function rename(array $stages, array $blocks, array $texts): void
    {
        DB::transaction(function () use ($stages, $blocks, $texts) {
            foreach ($stages as $old => $new) {
                $ids = DB::table('workflow_stages')->where('name', $old)->pluck('id')->all();
                // Имя в маршруте уникально: где новое уже есть (заведено руками), не трогаем.
                $ids = array_values(array_filter($ids, fn ($id) => ! DB::table('workflow_stages')->where('name', $new)
                    ->where('workflow_id', DB::table('workflow_stages')->where('id', $id)->value('workflow_id'))->exists()));
                if (! $ids) {
                    continue;
                }
                DB::table('workflow_stages')->whereIn('id', $ids)->update(['name' => $new]);
                $in = implode(',', array_map('intval', $ids));
                foreach (['to', 'from'] as $key) {
                    // Записи до `to_id` — по имени.
                    DB::update("update car_events set payload = jsonb_set(payload, '{{$key}}', to_jsonb(?::text)) where type = 'stage_entered'
                        and ((payload->>'{$key}_id')::bigint in ({$in}) or (payload->>'{$key}_id' is null and payload->>'{$key}' = ?))", [$new, $old]);
                }
            }
            foreach ($blocks as $old => $new) {
                DB::table('workflow_blocks')->where('name', $old)->update(['name' => $new]);
                DB::update("update car_events set payload = jsonb_set(payload, '{block}', to_jsonb(?::text)) where type = 'stage_entered' and payload->>'block' = ?", [$new, $old]);
            }
            foreach ($texts as $old => $new) {
                DB::table('workflow_blocks')->where('text', $old)->update(['text' => $new]);
            }
        });
    }
};
