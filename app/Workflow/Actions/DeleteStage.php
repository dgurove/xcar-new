<?php

namespace App\Workflow\Actions;

use App\Workflow\Position;
use App\Workflow\Stage;
use Illuminate\Validation\ValidationException;

final class DeleteStage
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    public function __invoke(Stage $stage): void
    {
        if (Position::where('stage_id', $stage->id)->exists()) {
            throw ValidationException::withMessages(['stage' => "На этапе «{$stage->name}» стоят офферы"]);
        }
        $workflow = $stage->workflow;
        $stage->delete();
        ($this->revalidate)($workflow);
    }
}
