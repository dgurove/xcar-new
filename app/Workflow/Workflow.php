<?php

namespace App\Workflow;

use App\Offers\CarPlace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Маршрут: блоки, в них этапы, между этапами исходы. Начало — первый этап
 * первого блока, конец — этап без исходов. Включить можно только маршрут
 * без дыр: иначе оффер молча встал бы в тупик на живой сделке.
 */
#[Fillable(['insurer_id', 'track', 'is_active'])]
class Workflow extends Model
{
    protected function casts(): array
    {
        return ['track' => Track::class, 'is_active' => 'bool'];
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('position');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)
            ->join('workflow_blocks', 'workflow_blocks.id', '=', 'workflow_stages.block_id')
            ->orderBy('workflow_blocks.position')->orderBy('workflow_stages.position')
            ->select('workflow_stages.*');
    }

    public function startStage(): ?Stage
    {
        return $this->stages()->with(['exits', 'block'])->first();
    }

    /** Что в маршруте сломано — списком, человеческим языком. Пустой список — маршрут цел. */
    public function problems(): array
    {
        /** @var Collection<int, Stage> $stages */
        $stages = $this->stages()->with(['exits', 'block'])->get();
        if ($stages->isEmpty()) {
            return ['В маршруте нет ни одного этапа'];
        }

        $problems = [];
        $own = $stages->pluck('id')->all();
        $reached = [];
        if ($stages->every(fn (Stage $s) => $s->exits->isNotEmpty())) {
            $problems[] = 'Из каждого этапа есть выход — маршрут никогда не кончается';
        }
        foreach ($stages as $stage) {
            foreach ($stage->exits as $exit) {
                if (! $exit->to_stage_id) {
                    $problems[] = "Кнопка «{$exit->label}» этапа «{$stage->name}» никуда не ведёт";
                } elseif (! in_array($exit->to_stage_id, $own, true)) {
                    $problems[] = "Кнопка «{$exit->label}» этапа «{$stage->name}» ведёт в чужой маршрут";
                } else {
                    $reached[] = $exit->to_stage_id;
                }
            }
            if ($stage->asks !== Asks::Nothing && ! $stage->awaitsManager()) {
                $problems[] = "Этап «{$stage->name}» просит менеджера что-то приложить, но кнопок менеджера у него нет";
            }
            if ($stage->waits_for === WaitsFor::Manager && ! $stage->awaitsManager()) {
                $problems[] = "Этап «{$stage->name}» ждёт менеджера, а нажать ему нечего";
            }
            if ($this->track === Track::Service && $stage->offer_state) {
                $problems[] = "Этап «{$stage->name}» меняет состояние продажи, хотя стоит в маршруте вывоза";
            }
            if ($this->track === Track::Sale && $stage->car_place) {
                $problems[] = "Этап «{$stage->name}» переставляет машину, хотя стоит в маршруте продажи";
            }
            if ($this->track === Track::Service && $stage->waits_for === WaitsFor::Manager) {
                $problems[] = "Этап «{$stage->name}» ждёт менеджера, а в вывозе менеджер не участвует";
            }
            if ($stage->exits->contains(fn (Outcome $e) => $e->actor === Actor::Timer) && $stage->timerMode() !== 'countdown') {
                $problems[] = "У этапа «{$stage->name}» есть исход по времени, но срока нет";
            }
        }
        foreach ($stages->slice(1) as $stage) {
            if (! in_array($stage->id, $reached, true)) {
                $problems[] = "На этап «{$stage->name}» не ведёт ни один исход";
            }
        }
        if ($this->track === Track::Service && $stages->every(fn (Stage $s) => $s->car_place !== CarPlace::Ours)) {
            $problems[] = 'Ни один этап не доводит машину до нашей площадки';
        }

        return array_values(array_unique($problems));
    }
}
