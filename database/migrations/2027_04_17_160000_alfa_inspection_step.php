<?php

use App\Offers\Offer;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Presets\Alfa;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Альфа: «Осмотр перед оплатой» (17.11.2026, владелец по CTTM Cargoline 5491/046/00861/26: «они нам осмотр согласовали, всё,
 * менеджер должен дать ответ, мы ничего им писать не должны»). В блоке «Реквизиты для счёта»:
 * — с «Нет реквизитов» и «Письмо с реквизитами» — «Поставщик согласовал осмотр» (сотрудник) → «Осмотр перед оплатой»;
 * — «Осмотр перед оплатой» (ход менеджера): «Осмотрел, берём» → «Нет реквизитов» (данные есть — проходит сам), «После осмотра
 *   не берём» → «Приём подтверждений» (сделка отменяется, как «Я передумал»).
 * Есть уже — не трогаем. Сделка CTTM (offer 195) стоит на «Письмо с реквизитами», письмо не ушло, а Альфа 16.11 осмотр
 * согласовала — переводим её в новый шаг: Роману — просьба ответить после осмотра.
 */
return new class extends Migration
{
    public function up(): void
    {
        $spec = Alfa::requisites()['inspection'];

        // Все пути с блоком «Реквизиты для счёта» (Альфа и скопированный с неё Энергогарант), а не только «оплата до вывоза».
        $workflows = Stage::where('name', 'Письмо с реквизитами')->pluck('workflow_id')->unique();
        foreach (\App\Workflow\Workflow::whereKey($workflows)->get() as $workflow) {
            if ($workflow->track !== Track::Sale || Stage::where('workflow_id', $workflow->id)->where('name', Alfa::INSPECTION)->exists()) {
                continue;
            }
            DB::transaction(function () use ($workflow, $spec) {
                $all = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name');
                $data = $all['Нет реквизитов'] ?? null;
                $letter = $all['Письмо с реквизитами'] ?? null;
                $bidding = $all['Приём подтверждений'] ?? null;
                if (! $data || ! $letter || ! $bidding) {
                    return;
                }
                Stage::where('workflow_id', $workflow->id)->where('block_id', $letter->block_id)->where('position', '>', $letter->position)->increment('position');
                $inspection = Stage::create([
                    'workflow_id' => $workflow->id, 'block_id' => $letter->block_id, 'position' => $letter->position + 1, 'name' => $spec['name'],
                    'waits_for' => $spec['waits_for'], 'limit_minutes' => $spec['limit_minutes'], 'deadline_source' => 'own', 'asks' => 'nothing',
                    'ask_title' => $spec['ask_title'], 'ask_text' => $spec['ask_text'], 'fields' => [], 'staff_fields' => [], 'template_id' => null,
                ]);
                foreach ([$data, $letter] as $from) {
                    $from->exits()->create(['label' => Alfa::INSPECTION_AGREED, 'actor' => 'staff', 'to_stage_id' => $inspection->id, 'position' => (int) $from->exits->max('position') + 1]);
                }
                // Платим мы (Р.О.С., ПРАЙМ) — сразу письмо с нашими реквизитами, иначе — данные покупателя (с ними шаг проходит сам).
                $inspection->exits()->create(['label' => 'Осмотрел, берём', 'actor' => 'manager', 'to_stage_id' => $data->id, 'position' => 0, 'branch' => Outcome::NOT_ROS]);
                $inspection->exits()->create(['label' => 'Осмотрел, берём', 'actor' => 'manager', 'to_stage_id' => $letter->id, 'position' => 1, 'branch' => Outcome::ROS]);
                $inspection->exits()->create(['label' => 'После осмотра не берём', 'actor' => 'manager', 'to_stage_id' => $bidding->id, 'position' => 2]);
                app(RevalidateWorkflow::class)($workflow);
            });
        }

        $this->cttm();
    }

    /** CTTM Cargoline 5491/046/00861/26: осмотр согласован 16.11, шаг всё ещё ждёт нашего письма с реквизитами. */
    private function cttm(): void
    {
        $offer = Offer::find(195);
        $position = $offer?->position(Track::Sale);
        if (! $offer || $offer->car?->claim_ref !== '5491/046/00861/26' || $position?->stage?->name !== 'Письмо с реквизитами' || $position->letterStatus() !== 'unsent') {
            return;
        }
        $exit = Outcome::where('stage_id', $position->stage_id)->where('label', Alfa::INSPECTION_AGREED)->first();
        if ($exit) {
            app(TakeExit::class)($offer, $exit, Actor::Staff, \App\Billing\Robot::user());
        }
    }

    public function down(): void {}
};
