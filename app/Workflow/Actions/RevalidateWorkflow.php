<?php

namespace App\Workflow\Actions;

use App\Workflow\Workflow;
use Illuminate\Validation\ValidationException;

/** Маршрут с дырами включённым не бывает: после правки он гаснет сам, включить его можно только целым. */
final class RevalidateWorkflow
{
    public function __invoke(Workflow $workflow, ?bool $active = null): array
    {
        $problems = $workflow->problems();
        if ($active === true && $problems) {
            throw ValidationException::withMessages(['workflow' => $problems]);
        }
        $workflow->update(['is_active' => $problems ? false : ($active ?? $workflow->is_active)]);

        return $problems;
    }
}
