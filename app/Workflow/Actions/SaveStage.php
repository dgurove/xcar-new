<?php

namespace App\Workflow\Actions;

use App\Users\User;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Workflow;
use Illuminate\Support\Facades\DB;

/**
 * Этап целиком: свойства и исходы одним сохранением. Срок поменяли — `retime` (по умолчанию да) доводит его до сделок на этапе
 * (`RetimeStage`). Что не так с маршрутом после правки (он гаснет сам, `RevalidateWorkflow`), — `problems` у результата.
 */
final class SaveStage
{
    /** Что сделало последнее сохранение: сколько сделок получили новый срок и что не так с маршрутом. */
    public int $retimed = 0;

    /** @var list<string> */
    public array $problems = [];

    public function __construct(private RevalidateWorkflow $revalidate, private RetimeStage $retime) {}

    public function __invoke(Workflow $workflow, Block $block, array $data, ?Stage $stage = null, ?User $by = null): Stage
    {
        return DB::transaction(function () use ($workflow, $block, $data, $stage, $by) {
            $stage ??= new Stage(['workflow_id' => $workflow->id, 'position' => ($block->stages()->max('position') ?? -1) + 1]);
            [$oldMinutes, $oldWorkdays] = [$stage->limit_minutes, (bool) $stage->limit_workdays];
            $stage->fill([
                'block_id' => $block->id,
                'name' => trim($data['name']),
                'waits_for' => $data['waits_for'],
                'limit_minutes' => self::minutes($data),
                'limit_workdays' => ($data['limit_unit'] ?? null) === 'workdays' && self::minutes($data) !== null,
                'deadline_source' => $data['deadline_source'] ?? 'own',
                'offer_state' => $data['offer_state'] ?: null,
                'car_place' => $data['car_place'] ?: null,
                'ask_title' => trim((string) ($data['ask_title'] ?? '')) ?: null,
                'ask_text' => trim((string) ($data['ask_text'] ?? '')) ?: null,
                'asks' => $data['asks'] ?? 'nothing',
                'fields' => Stage::keyFields($data['fields'] ?? []),
                'staff_fields' => Stage::keyFields($data['staff_fields'] ?? []),
                'template_id' => $data['template_id'] ?: null,
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

            $this->problems = ($this->revalidate)($workflow);
            $this->retimed = $by && $stage->wasChanged(['limit_minutes', 'limit_workdays']) && ($data['retime'] ?? true)
                ? ($this->retime)($stage, $oldMinutes, $oldWorkdays, $by) : 0;

            return $stage;
        });
    }

    /** «Сколько ждём» в минутах: мин, ч, дн, раб. дн (рабочие дни — сутками, выходные считает `Stage::addLimit`). */
    public static function minutes(array $data): ?int
    {
        $value = $data['limit_value'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value * match ($data['limit_unit'] ?? 'hours') {
            'minutes' => 1, 'days', 'workdays' => 1440, default => 60,
        };
    }
}
