<?php

use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Автомобиль у страхователя забирает сам менеджер сделки (04.10.2026): рядом с «Передачей автомобиля покупателю»
 * (наш шаг, машина у нас) встаёт «Менеджер забирает автомобиль» — его шаг. Исходы, что вели на передачу, раздваиваются
 * веткой: «отдаём мы» — туда же, «забирает сам» — на новый этап (`Outcome::fits`, `Deal::buyerPicksUp`). Боевые
 * маршруты — данные, поэтому этап вставляется по именам, как в `Route::pickupSegment` (снимок ниже); сейчас такая
 * передача есть только у Совкомбанка, обе ветки — «для клиента» и «на себя».
 *
 * Заодно тексты для менеджера — без точки в конце (правило кита), человеческая «Согласование с поставщиком» и после
 * согласия — своя фраза у «Подтвердили покупку поставщику».
 */
return new class extends Migration
{
    private const DAY = 1440;

    public function up(): void
    {
        foreach (Workflow::where('track', Track::Sale)->get() as $workflow) {
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get();
            $touched = false;
            foreach (['', ' — на себя'] as $suffix) {
                $release = $stages->firstWhere('name', 'Передача автомобиля покупателю'.$suffix);
                if (! $release || $stages->firstWhere('name', 'Менеджер забирает автомобиль'.$suffix)) {
                    continue;
                }
                $next = $release->exits->firstWhere('label', 'Автомобиль передан')?->to_stage_id;
                if (! $next) {
                    continue;
                }
                DB::transaction(function () use ($workflow, $release, $suffix, $next) {
                    Stage::where('block_id', $release->block_id)->where('position', '>', $release->position)->increment('position');
                    $pickup = Stage::create([
                        'workflow_id' => $workflow->id, 'block_id' => $release->block_id, 'position' => $release->position + 1,
                        'name' => 'Менеджер забирает автомобиль'.$suffix, 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY,
                        'deadline_source' => 'own', 'asks' => 'nothing', 'fields' => [], 'staff_fields' => [],
                        'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Свяжитесь с владельцем, договоритесь о времени и заберите автомобиль',
                    ]);
                    $pickup->exits()->create(['label' => 'Автомобиль забрал', 'actor' => 'manager', 'to_stage_id' => $next, 'position' => 0]);
                    $pickup->exits()->create(['label' => 'Автомобиль передан', 'actor' => 'staff', 'to_stage_id' => $next, 'position' => 1]);
                    foreach (Outcome::where('to_stage_id', $release->id)->whereNull('branch')->get() as $in) {
                        $in->update(['branch' => Outcome::WE_HAND]);
                        Outcome::create(['stage_id' => $in->stage_id, 'to_stage_id' => $pickup->id, 'label' => $in->label, 'actor' => $in->actor,
                            'confirm' => $in->confirm, 'position' => $in->position, 'branch' => Outcome::BUYER_PICKS]);
                    }
                });
                $touched = true;
            }
            if ($touched) {
                // Включённый проверяем с `true`: дыра — ошибка и откат, а не молча погасший боевой маршрут.
                app(RevalidateWorkflow::class)($workflow->fresh(), $workflow->is_active ?: null);
            }
        }

        DB::table('workflow_blocks')->where('text', 'Уведомили поставщика о покупке и ждём его ответа.')
            ->update(['text' => 'Согласовываем покупку с поставщиком, обычно до суток']);
        DB::statement("update workflow_blocks set text = regexp_replace(text, '\\.\\s*$', '') where text ~ '[^.]\\.\\s*$'");
        DB::table('workflow_stages')->where('name', 'like', 'Подтвердили покупку поставщику%')->whereNull('ask_text')
            ->update(['ask_text' => 'Поставщик согласовал продажу, оформляем покупку']);
        DB::statement("update workflow_stages set ask_text = regexp_replace(ask_text, '\\.\\s*$', '') where ask_text ~ '[^.]\\.\\s*$'");
    }

    public function down(): void {}
};
