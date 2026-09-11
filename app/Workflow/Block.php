<?php

namespace App\Workflow;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Блок маршрута — шаг, который видит менеджер. */
#[Fillable(['workflow_id', 'name', 'text', 'position'])]
class Block extends Model
{
    protected $table = 'workflow_blocks';

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class, 'block_id')->orderBy('position');
    }
}
