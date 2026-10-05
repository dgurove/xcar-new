<?php

use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Т-Страхование: получение тремя шагами (06.10.2026, владелец: «сначала отмечает, что связался со страхователем, потом —
 * что забрал машину»). «Контакты владельца переданы менеджеру» больше не просит договор: его выход «Связался с
 * владельцем» → «Менеджер забирает автомобиль» («Автомобиль забрал», за него — наш «Автомобиль передан») → «Подписанный
 * договор» с «Договор приложен» → «Оплата подбора». Снимок — `Presets\TBank`. Открытая просьба первого шага
 * переименовывается: сделка, что на нём стоит, продолжает со звонка.
 */
return new class extends Migration
{
    private const DAY = 1440;

    public function up(): void
    {
        // По признаку маршрута, а не по имени вендора: заготовку «Как у Т-Страхования» могли завести и другому.
        foreach (Workflow::where('track', Track::Sale)->get() as $workflow) {
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name');
            $contact = $stages['Контакты владельца переданы менеджеру'] ?? null;
            $pay = $stages['Оплата подбора'] ?? null;
            if (! $contact || ! $pay || isset($stages['Подписанный договор'])
                || ! $contact->exits->contains(fn (Outcome $e) => $e->to_stage_id === $pay->id && $e->label === 'Договор приложен')) {
                continue;
            }
            DB::transaction(fn () => $this->split($workflow, $contact, $pay));
        }
    }

    private function split(Workflow $workflow, Stage $contact, Stage $pay): void
    {
        Stage::where('block_id', $contact->block_id)->where('position', '>', $contact->position)->increment('position', 2);
        $pickup = Stage::create([
            'workflow_id' => $workflow->id, 'block_id' => $contact->block_id, 'position' => $contact->position + 1,
            'name' => 'Менеджер забирает автомобиль', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY,
            'deadline_source' => 'own', 'asks' => 'nothing', 'fields' => [], 'staff_fields' => [],
            'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Заберите автомобиль у владельца',
        ]);
        $signed = Stage::create([
            'workflow_id' => $workflow->id, 'block_id' => $contact->block_id, 'position' => $contact->position + 2,
            'name' => 'Подписанный договор', 'waits_for' => 'manager', 'limit_minutes' => 2 * self::DAY,
            'deadline_source' => 'own', 'asks' => 'document', 'fields' => [], 'staff_fields' => [],
            'ask_title' => 'Приложите подписанный договор', 'ask_text' => 'Приложите ДКП, подписанный собственником и покупателем. Без него сделка не закроется',
        ]);
        $pickup->exits()->create(['label' => 'Автомобиль забрал', 'actor' => 'manager', 'to_stage_id' => $signed->id, 'position' => 0, 'confirm' => 'Забрали автомобиль?']);
        $pickup->exits()->create(['label' => 'Автомобиль передан', 'actor' => 'staff', 'to_stage_id' => $signed->id, 'position' => 1]);
        $signed->exits()->create(['label' => 'Договор приложен', 'actor' => 'manager', 'to_stage_id' => $pay->id, 'position' => 0]);

        Outcome::where('stage_id', $contact->id)->where('to_stage_id', $pay->id)
            ->update(['label' => 'Связался с владельцем', 'to_stage_id' => $pickup->id]);
        $contact->update(['asks' => 'nothing', 'limit_minutes' => self::DAY, 'ask_title' => 'Свяжитесь с владельцем',
            'ask_text' => 'Свяжитесь с владельцем автомобиля и договоритесь о передаче']);
        // Файлы, что уже приложили к просьбе первого шага, остаются при ней: в «Документах» сделки они видны и так.
        Requirement::where('stage_id', $contact->id)->whereNull('done_at')
            ->update(['asks' => 'nothing', 'title' => 'Свяжитесь с владельцем', 'text' => 'Свяжитесь с владельцем автомобиля и договоритесь о передаче']);
        app(RevalidateWorkflow::class)($workflow->fresh(), $workflow->is_active ?: null);
    }

    public function down(): void {}
};
