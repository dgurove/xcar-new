<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\Destination;
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
    public function nextBlocks(Deal|Destination|null $deal = null): Collection
    {
        // Ветка не этой сделки — не путь вперёд: иначе развилка «Покупаю / Забираю в гараж» обрывала бы лестницу всем.
        // И уходят только с этапов, до которых эта сделка в блоке доходит: «Подтвердили покупку поставщику» за
        // «Покупаю» гаражной сделке не встретится, и его выход в «Оплату» второй дорогой не считается.
        return $this->reachable($deal)->flatMap(fn (Stage $s) => $s->exits->filter(fn (Outcome $e) => $e->fits($deal))->map(fn (Outcome $e) => $e->to?->block))
            ->filter()->reject(fn (self $b) => $b->is($this))->unique('id')->values();
    }

    /**
     * Этапы блока, до которых сделка доходит: от входов (в них не ведёт ни один исход изнутри блока) по исходам её
     * ветки. Входов нет (блок — кольцо) — все этапы, как раньше.
     *
     * @return Collection<int, Stage>
     */
    private function reachable(Deal|Destination|null $deal): Collection
    {
        $inner = $this->stages->flatMap(fn (Stage $s) => $s->exits->pluck('to_stage_id'))->all();
        $queue = $this->stages->reject(fn (Stage $s) => in_array($s->id, $inner, true))->values()->all();
        if (! $queue) {
            return $this->stages;
        }
        $seen = [];
        while ($stage = array_shift($queue)) {
            if (isset($seen[$stage->id])) {
                continue;
            }
            $seen[$stage->id] = $stage;
            foreach ($stage->exits as $exit) {
                if ($exit->fits($deal) && ($next = $this->stages->firstWhere('id', $exit->to_stage_id))) {
                    $queue[] = $next;
                }
            }
        }

        return collect(array_values($seen));
    }
}
