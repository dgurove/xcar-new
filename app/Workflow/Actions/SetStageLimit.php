<?php

namespace App\Workflow\Actions;

use App\Users\User;
use App\Workflow\DeadlineSource;
use App\Workflow\Stage;
use Illuminate\Support\Facades\DB;

/**
 * Срок этапа с экрана «Таймеры» (19.01.2027): только «сколько ждём» — у этапа со своим сроком; дошёл до сделок на этапе
 * (`RetimeStage`), маршрут перепроверен (`RevalidateWorkflow`: сняли срок у этапа с исходом по таймеру — маршрут гаснет,
 * причина возвращается).
 *
 * @return array{retimed: int, problems: list<string>}
 */
final class SetStageLimit
{
    public function __construct(private RetimeStage $retime, private RevalidateWorkflow $revalidate) {}

    public function __invoke(Stage $stage, ?int $minutes, bool $workdays, bool $retime, User $by): array
    {
        if ($stage->deadline_source !== DeadlineSource::Own) {
            return ['retimed' => 0, 'problems' => []];
        }

        return DB::transaction(function () use ($stage, $minutes, $workdays, $retime, $by) {
            [$oldMinutes, $oldWorkdays] = [$stage->limit_minutes, (bool) $stage->limit_workdays];
            $stage->update(['limit_minutes' => $minutes, 'limit_workdays' => $workdays && $minutes !== null]);
            $retimed = $retime ? ($this->retime)($stage, $oldMinutes, $oldWorkdays, $by) : 0;

            return ['retimed' => $retimed, 'problems' => ($this->revalidate)($stage->workflow)];
        });
    }
}
