<?php

namespace App\Workflow\Actions;

use App\Mail\Scope;
use App\Mail\Template;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Заполнить пустой маршрут заготовкой и включить. Дырявая заготовка не
 * включится — `RevalidateWorkflow` и есть её приёмка.
 */
final class ApplyPreset
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    public function __invoke(Workflow $workflow, Preset $preset): void
    {
        if ($preset->track() !== $workflow->track) {
            throw ValidationException::withMessages(['workflow' => 'Заготовка с другой ветки']);
        }
        if ($workflow->stages()->exists()) {
            throw ValidationException::withMessages(['workflow' => 'Маршрут уже заполнен']);
        }
        $route = $preset->route();
        $rows = $route->stages();

        DB::transaction(function () use ($workflow, $route, $rows) {
            $letters = [];
            foreach ($route->letters() as $key => $letter) {
                $letters[$key] = Template::firstOrCreate(['name' => $letter['name'], 'scope' => Scope::Offers], $letter)->id;
            }

            // Блоки — только те, на которые ссылаются этапы, в порядке заготовки.
            $used = array_unique(array_column($rows, 'block'));
            $blocks = [];
            $bi = 0;
            foreach ($route->blocks() as $key => $block) {
                if (in_array($key, $used, true)) {
                    $blocks[$key] = $workflow->blocks()->create($block + ['position' => $bi++]);
                }
            }

            $stages = [];
            $positions = [];
            foreach ($rows as $key => $row) {
                $block = $blocks[$row['block']];
                $stages[$key] = $block->stages()->create([
                    'workflow_id' => $workflow->id,
                    'position' => $positions[$row['block']] = ($positions[$row['block']] ?? -1) + 1,
                    'name' => $row['name'],
                    'waits_for' => $row['waits_for'] ?? 'us',
                    'limit_minutes' => $row['limit_minutes'] ?? null,
                    'deadline_source' => $row['deadline_source'] ?? 'own',
                    'offer_state' => $row['offer_state'] ?? null,
                    'car_place' => $row['car_place'] ?? null,
                    'ask_title' => $row['ask_title'] ?? null,
                    'ask_text' => $row['ask_text'] ?? null,
                    'asks' => $row['asks'] ?? 'nothing',
                    'fields' => Stage::keyFields($row['fields'] ?? []),
                    'staff_fields' => Stage::keyFields($row['staff_fields'] ?? []),
                    'template_id' => isset($row['letter']) ? $letters[$row['letter']] : null,
                ]);
            }

            foreach ($rows as $key => $row) {
                foreach ($row['exits'] ?? [] as $i => [$label, $actor, $to]) {
                    $stages[$key]->exits()->create(['label' => $label, 'actor' => $actor, 'to_stage_id' => $stages[$to]->id, 'position' => $i]);
                }
            }

            ($this->revalidate)($workflow, true);
        });
    }
}
