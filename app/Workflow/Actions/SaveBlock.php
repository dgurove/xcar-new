<?php

namespace App\Workflow\Actions;

use App\Workflow\Block;
use App\Workflow\Workflow;

final class SaveBlock
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    public function __invoke(Workflow $workflow, array $data, ?Block $block = null): Block
    {
        $block ??= new Block(['workflow_id' => $workflow->id, 'position' => ($workflow->blocks()->max('position') ?? -1) + 1]);
        $block->fill(['name' => trim($data['name']), 'text' => trim((string) ($data['text'] ?? '')) ?: null])->save();
        ($this->revalidate)($workflow);

        return $block;
    }
}
