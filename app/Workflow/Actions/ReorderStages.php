<?php

namespace App\Workflow\Actions;

use App\Workflow\Block;
use App\Workflow\Stage;
use App\Workflow\Workflow;
use Illuminate\Support\Facades\DB;

/** Порядок из редактора: блоки по порядку, в каждом — этапы. Этап, перетащенный в другой блок, переезжает. */
final class ReorderStages
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    /** @param array<int, array{id: int, stages: list<int>}> $blocks */
    public function __invoke(Workflow $workflow, array $blocks): void
    {
        DB::transaction(function () use ($workflow, $blocks) {
            foreach (array_values($blocks) as $bi => $row) {
                $block = $workflow->blocks()->findOrFail($row['id']);
                $block->update(['position' => $bi]);
                foreach (array_values($row['stages'] ?? []) as $si => $stageId) {
                    Stage::where('workflow_id', $workflow->id)->whereKey($stageId)->update(['block_id' => $block->id, 'position' => $si]);
                }
            }
            ($this->revalidate)($workflow);
        });
    }
}
