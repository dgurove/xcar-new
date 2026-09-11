<?php

namespace App\Workflow;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Исход — кнопка, уводящая с этапа на другой этап. */
#[Fillable(['stage_id', 'to_stage_id', 'label', 'actor', 'confirm', 'position'])]
class Outcome extends Model
{
    protected $table = 'workflow_exits';

    protected function casts(): array
    {
        return ['actor' => Actor::class];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }
}
