<?php

use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Куда вывозят (04.10.2026): в боевые маршруты вывоза на месте, по именам этапов (как `Presets\Pickup`, снимок ниже),
 * вставляются концы «Стоит у менеджера» и «Стоит у нас» и два «Забрал» ответственного на «Вывоз и осмотр»; прежний
 * выход становится веткой парковки. На «Вывоз и осмотр» — поля «Дата вывоза», «Адрес», «Контакт», если своих нет.
 * Блоки — в конец: лестница не ведёт к блоку раньше текущего. Чего не нашли — в лог, маршрут не трогаем.
 */
return new class extends Migration
{
    private const ENDS = [
        'keeper' => ['block' => ['name' => 'Стоит у менеджера', 'text' => 'Автомобиль вывез и держит у себя менеджер.'], 'car_place' => 'keeper'],
        'ours' => ['block' => ['name' => 'Стоит у нас', 'text' => 'Автомобиль стоит у нас, не на парковке.'], 'car_place' => 'with_us'],
    ];

    private const FIELDS = [['label' => 'Дата вывоза', 'type' => 'date'], ['label' => 'Адрес', 'type' => 'textarea'], ['label' => 'Контакт']];

    public function up(): void
    {
        foreach (Workflow::where('track', Track::Service)->get() as $workflow) {
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get();
            if ($stages->contains('name', 'Стоит у нас')) {
                continue;
            }
            $pickup = $stages->firstWhere('name', 'Вывоз и осмотр');
            $old = $pickup?->exits->firstWhere('label', 'Автомобиль вывезен, осмотр проведён');
            if (! $old) {
                Log::warning("Вывоз к менеджеру: маршрут {$workflow->id} — не нашли «Вывоз и осмотр»");

                continue;
            }
            DB::transaction(function () use ($workflow, $pickup, $old) {
                $old->update(['branch' => 'yard']);
                $position = (int) Block::where('workflow_id', $workflow->id)->max('position');
                $next = (int) $pickup->exits->max('position');
                foreach (self::ENDS as $branch => $end) {
                    $block = Block::create(['workflow_id' => $workflow->id, 'position' => ++$position] + $end['block']);
                    $stage = Stage::create([
                        'workflow_id' => $workflow->id, 'block_id' => $block->id, 'position' => 0, 'name' => $end['block']['name'],
                        'waits_for' => 'nobody', 'deadline_source' => 'own', 'car_place' => $end['car_place'], 'asks' => 'nothing',
                        'fields' => [], 'staff_fields' => [],
                    ]);
                    Outcome::create(['stage_id' => $pickup->id, 'to_stage_id' => $stage->id, 'label' => 'Забрал', 'actor' => 'keeper', 'position' => ++$next, 'branch' => $branch]);
                }
                if (! $pickup->staff_fields) {
                    $pickup->update(['staff_fields' => Stage::keyFields(self::FIELDS)]);
                }
                app(RevalidateWorkflow::class)($workflow->fresh(), $workflow->is_active ?: null);
            });
        }
    }

    public function down(): void {}
};
