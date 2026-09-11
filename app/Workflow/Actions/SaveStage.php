<?php

namespace App\Workflow\Actions;

use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Workflow;
use Illuminate\Support\Facades\DB;

/** Этап целиком: свойства и исходы одним сохранением. */
final class SaveStage
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    public function __invoke(Workflow $workflow, Block $block, array $data, ?Stage $stage = null): Stage
    {
        return DB::transaction(function () use ($workflow, $block, $data, $stage) {
            $stage ??= new Stage(['workflow_id' => $workflow->id, 'position' => ($block->stages()->max('position') ?? -1) + 1]);
            $stage->fill([
                'block_id' => $block->id,
                'name' => trim($data['name']),
                'waits_for' => $data['waits_for'],
                'limit_minutes' => $this->minutes($data),
                'deadline_source' => $data['deadline_source'] ?? 'own',
                'offer_state' => $data['offer_state'] ?: null,
                'car_place' => $data['car_place'] ?: null,
                'ask_title' => trim((string) ($data['ask_title'] ?? '')) ?: null,
                'ask_text' => trim((string) ($data['ask_text'] ?? '')) ?: null,
                'asks' => $data['asks'] ?? 'nothing',
                'fields' => Stage::keyFields($data['fields'] ?? []),
                'staff_fields' => Stage::keyFields($data['staff_fields'] ?? []),
            ])->save();

            $keep = [];
            foreach (array_values($data['exits'] ?? []) as $i => $row) {
                $label = trim((string) ($row['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $exit = ! empty($row['id']) ? $stage->exits()->find($row['id']) : null;
                $exit ??= new Outcome(['stage_id' => $stage->id]);
                $exit->fill([
                    'label' => $label,
                    'actor' => $row['actor'] ?? 'staff',
                    'to_stage_id' => $row['to_stage_id'] ?: null,
                    'confirm' => trim((string) ($row['confirm'] ?? '')) ?: null,
                    'position' => $i,
                ])->save();
                $keep[] = $exit->id;
            }
            $stage->exits()->whereNotIn('id', $keep)->delete();

            ($this->revalidate)($workflow);

            return $stage;
        });
    }

    private function minutes(array $data): ?int
    {
        $value = $data['limit_value'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value * match ($data['limit_unit'] ?? 'hours') {
            'minutes' => 1, 'days' => 1440, default => 60,
        };
    }
}
