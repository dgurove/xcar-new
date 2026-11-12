<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Без «ПРАЙМ» в пути Совкомбанка (12.11.2026, владелец: с НДС продаёт Р.О.С. — «везде должно быть написано рос»): шаг
 * «Оплата счёта ПРАЙМ» — «Оплата счёта», как у Альфы; просьба и тексты блоков — без продавца, он в счёте и договоре сделки.
 * Правится только прежняя подпись: переписанное руками остаётся. Журнал пути (`car_events` `stage_entered`) — на новое имя.
 */
return new class extends Migration
{
    private const STAGE = ['Оплата счёта ПРАЙМ', 'Оплата счёта'];

    private const ASK = ['Оплатите счёт ПРАЙМ по реквизитам и приложите платёжное поручение', 'Оплатите счёт по реквизитам и приложите платёжное поручение'];

    private const TEXTS = [
        'Укажите покупателя: счёт ПРАЙМ и договор купли-продажи придут вместе. Оплатите счёт' => 'Укажите покупателя: счёт и договор купли-продажи придут вместе. Оплатите счёт',
        'ПРАЙМ оплачивает автомобиль поставщику' => 'Оплачиваем автомобиль поставщику',
        'Автомобиль взяли себе: оплачиваем его поставщику и оформляем документы на ПРАЙМ' => 'Автомобиль взяли себе: оплачиваем его поставщику и оформляем документы на нас',
    ];

    public function up(): void
    {
        $this->apply(self::STAGE, self::ASK, self::TEXTS);
    }

    public function down(): void
    {
        $this->apply(array_reverse(self::STAGE), array_reverse(self::ASK), array_flip(self::TEXTS));
    }

    private function apply(array $stage, array $ask, array $texts): void
    {
        DB::transaction(function () use ($stage, $ask, $texts) {
            [$old, $new] = $stage;
            $ids = DB::table('workflow_stages')->where('name', $old)->pluck('id')->all();
            // Имя в маршруте уникально: где новое уже есть, не трогаем.
            $ids = array_values(array_filter($ids, fn ($id) => ! DB::table('workflow_stages')->where('name', $new)
                ->where('workflow_id', DB::table('workflow_stages')->where('id', $id)->value('workflow_id'))->exists()));
            if ($ids) {
                DB::table('workflow_stages')->whereIn('id', $ids)->update(['name' => $new]);
                $in = implode(',', array_map('intval', $ids));
                foreach (['to', 'from'] as $key) {
                    DB::update("update car_events set payload = jsonb_set(payload, '{{$key}}', to_jsonb(?::text)) where type = 'stage_entered'
                        and ((payload->>'{$key}_id')::bigint in ({$in}) or (payload->>'{$key}_id' is null and payload->>'{$key}' = ?))", [$new, $old]);
                }
            }
            DB::table('workflow_stages')->where('ask_text', $ask[0])->update(['ask_text' => $ask[1]]);
            foreach ($texts as $from => $to) {
                DB::table('workflow_blocks')->where('text', $from)->update(['text' => $to]);
            }
        });
    }
};
