<?php

namespace App\Workflow;

use App\Offers\OfferState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /** Тупик срыва: все этапы блока снимают машину или отправляют в архив. */
    public function isDeadEnd(): bool
    {
        return $this->stages->isNotEmpty()
            && $this->stages->every(fn (Stage $s) => in_array($s->offer_state, [OfferState::Cancelled, OfferState::Archived], true));
    }

    /**
     * Блоки, в которые уводят исходы этого блока. Движение внутри блока для
     * менеджера ничего не меняет, и лестница о нём не знает.
     *
     * @return Collection<int, self>
     */
    public function nextBlocks(): Collection
    {
        return $this->stages->flatMap(fn (Stage $s) => $s->exits->map(fn (Outcome $e) => $e->to?->block))
            ->filter()->reject(fn (self $b) => $b->is($this))->unique('id')->values();
    }
}
