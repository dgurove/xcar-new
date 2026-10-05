<?php

use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Совкомбанк без согласования с поставщиком (владелец, 05.10.2026: «Совком обязывает нас вывозить все тачки без их
 * подтверждения»). «Подтверждение принято» ведёт сразу к счёту менеджеру, гаражной «платим мы» — к оплате поставщику.
 * Уходят «Уведомили поставщика о покупке», «Подтвердили покупку поставщику» (обе ветки), «Отказ поставщика» и вся
 * ветка «— на себя». Кто стоял на них — переходит в начало своей ветки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workflow = Vendor::whereRaw("name ilike '%совком%'")->first()?->workflow(Track::Sale);
        if (! $workflow) {
            return;
        }
        $stages = Stage::where('workflow_id', $workflow->id)->get()->keyBy('name');
        $invoice = $stages['Счёт выставлен менеджеру'] ?? null;
        $claimed = $stages['Уведомили поставщика о покупке'] ?? null;
        if (! $invoice || ! $claimed) {
            return;
        }
        $garage = $stages['Оплата поставщику — в гараж'] ?? null;
        $doomed = $stages->filter(fn (Stage $s) => in_array($s->name, ['Уведомили поставщика о покупке', 'Подтвердили покупку поставщику', 'Подтвердили покупку поставщику — в гараж', 'Отказ поставщика'], true)
            || str_ends_with($s->name, ' — на себя'));

        DB::transaction(function () use ($workflow, $stages, $invoice, $claimed, $garage, $doomed) {
            $invoice->update(['offer_state' => 'sold']);
            $garage?->update(['offer_state' => 'sold']);
            foreach (['Приём подтверждений', 'Выбор подтверждения'] as $name) {
                foreach (($stages[$name] ?? null)?->exits()->where('to_stage_id', $claimed->id)->get() ?? [] as $exit) {
                    $exit->update(['to_stage_id' => $invoice->id, 'branch' => Outcome::BUYER]);
                    if ($garage) {
                        Outcome::create(['stage_id' => $exit->stage_id, 'to_stage_id' => $garage->id, 'label' => $exit->label, 'actor' => $exit->actor, 'position' => $exit->position, 'branch' => Outcome::GARAGE]);
                    }
                }
            }
            // Возвраты в убранные этапы из оставшихся (если их дорисовали руками) — в счёт.
            DB::table('workflow_exits')->whereIn('to_stage_id', $doomed->pluck('id'))->whereNotIn('stage_id', $doomed->pluck('id'))
                ->update(['to_stage_id' => $invoice->id]);

            $enter = app(EnterStage::class);
            Position::whereIn('stage_id', $doomed->pluck('id'))->with('offer')->get()->each(function (Position $p) use ($enter, $invoice, $garage) {
                $deal = $p->offer->deal()->first();
                $enter($p->offer, $garage && $deal?->isGarageUs() ? $garage : $invoice);
            });

            Stage::whereIn('id', $doomed->pluck('id'))->delete();
            DB::table('workflow_blocks')->where('workflow_id', $workflow->id)->where('name', 'Оформляем на нас')
                ->update(['text' => 'Машина уходит к Вам в гараж: оплачиваем её поставщику и оформляем документы на нас']);
            DB::table('workflow_blocks')->where('workflow_id', $workflow->id)
                ->whereNotExists(fn ($q) => $q->from('workflow_stages')->whereColumn('workflow_stages.block_id', 'workflow_blocks.id'))
                ->whereIn('name', ['Согласование с поставщиком', 'Отказ поставщика', 'Получение автомобиля', 'Оплата после передачи', 'Оформление документов'])
                ->delete();
            app(RevalidateWorkflow::class)($workflow);
        });
    }

    public function down(): void {}
};
