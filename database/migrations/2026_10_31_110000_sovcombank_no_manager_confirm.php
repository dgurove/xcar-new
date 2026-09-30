<?php

use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Совкомбанк: согласовал поставщик — покупка решена, «Согласие менеджера» из продажи убрано (владелец, 30.09.2026).
 * «Поставщик согласовал» ведёт сразу в «Подтвердили покупку поставщику», рядом — «Поставщик согласовал, на себя».
 * Кто стоит на согласии менеджера — переходит дальше по ветке «для клиента», просьба к менеджеру закрывается.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workflow = Vendor::whereRaw("name ilike '%совком%'")->first()?->workflow(Track::Sale);
        if (! $workflow) {
            return;
        }
        $stage = fn (string $name) => Stage::where('workflow_id', $workflow->id)->where('name', $name)->first();
        $claimed = $stage('Уведомили поставщика о покупке');
        $consent = $stage('Согласие менеджера');
        $confirmed = $stage('Подтвердили покупку поставщику');
        $self = $stage('Подтвердили покупку поставщику — на себя');
        if (! $claimed || ! $consent || ! $confirmed) {
            return;
        }

        DB::transaction(function () use ($workflow, $claimed, $consent, $confirmed, $self) {
            $claimed->exits()->where('to_stage_id', $consent->id)->update(['to_stage_id' => $confirmed->id, 'position' => 0]);
            $claimed->exits()->where('label', 'Поставщик отказал')->update(['position' => 2]);
            if ($self) {
                $claimed->exits()->create(['label' => 'Поставщик согласовал, на себя', 'actor' => 'staff', 'to_stage_id' => $self->id, 'position' => 1]);
            }
            // Возвраты на согласие (если их кто-то дорисовал руками) — туда же, куда вело согласие «для клиента».
            DB::table('workflow_exits')->where('to_stage_id', $consent->id)->update(['to_stage_id' => $confirmed->id]);

            $enter = app(EnterStage::class);
            Position::where('stage_id', $consent->id)->with('offer')->get()->each(fn (Position $p) => $enter($p->offer, $confirmed));

            $consent->delete();
            app(RevalidateWorkflow::class)($workflow);
        });
    }

    public function down(): void {}
};
