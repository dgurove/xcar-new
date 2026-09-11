<?php

namespace App\Workflow\Actions;

use App\Workflow\Block;
use Illuminate\Validation\ValidationException;

final class DeleteBlock
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    public function __invoke(Block $block): void
    {
        if ($block->stages()->exists()) {
            throw ValidationException::withMessages(['block' => "В блоке «{$block->name}» есть этапы"]);
        }
        $workflow = $block->workflow;
        $block->delete();
        ($this->revalidate)($workflow);
    }
}
