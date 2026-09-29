<?php

use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Каркаде: машины стоят на площадках поставщика, а не у нас. Владелец: «мы выкупаем, менеджер платит нам».
 * После оплаты менеджера — «Оплата поставщику» (наш шаг, дата и номер платёжки), дальше «Выдача на площадке
 * поставщика» ждёт менеджера: «Автомобиль забрал». Этапы правятся на месте — на них уже стоят предложения.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workflow = Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->first()?->workflow(Track::Sale);
        if (! $workflow) {
            return;
        }
        $check = Stage::where('workflow_id', $workflow->id)->where('name', 'Проверка оплаты')->first();
        $release = Stage::where('workflow_id', $workflow->id)->where('name', 'Передача автомобиля покупателю')->first();
        if (! $check || ! $release) {
            return;
        }
        DB::transaction(function () use ($workflow, $check, $release) {
            $release->block?->update(['name' => 'Получение автомобиля', 'text' => 'Автомобиль оплачен, его можно забирать с площадки поставщика.']);

            $pay = Stage::create([
                'workflow_id' => $workflow->id, 'block_id' => $check->block_id, 'position' => $check->position + 1, 'name' => 'Оплата поставщику',
                'waits_for' => 'us', 'limit_minutes' => 2 * 1440, 'deadline_source' => 'own', 'asks' => 'nothing',
                'fields' => [], 'staff_fields' => Stage::keyFields([['label' => 'Дата оплаты'], ['label' => 'Номер платёжки']]),
            ]);
            $pay->exits()->create(['label' => 'Поставщику оплачено', 'actor' => 'staff', 'to_stage_id' => $release->id, 'position' => 0]);
            $check->exits()->where('label', 'Оплата получена')->update(['to_stage_id' => $pay->id]);

            $release->update([
                'name' => 'Выдача на площадке поставщика', 'waits_for' => 'manager', 'limit_minutes' => 5 * 1440,
                'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Автомобиль оплачен поставщику. Заберите его с площадки и отметьте, что забрали.',
                'staff_fields' => Stage::keyFields([['label' => 'Адрес площадки', 'type' => 'textarea'], ['label' => 'Контакт на площадке'], ['label' => 'Дата выдачи']]),
            ]);
            $won = $release->exits()->where('label', 'Автомобиль передан')->value('to_stage_id');
            if ($won && ! $release->exits()->where('label', 'Автомобиль забрал')->exists()) {
                $release->exits()->create(['label' => 'Автомобиль забрал', 'actor' => 'manager', 'to_stage_id' => $won, 'position' => 0]);
                $release->exits()->where('label', 'Автомобиль передан')->update(['position' => 1]);
            }
        });
        app(RevalidateWorkflow::class)($workflow->fresh(), true);
    }

    public function down(): void {}
};
