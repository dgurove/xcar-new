<?php

use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Database\Migrations\Migration;

/**
 * Шаг «Менеджер забирает автомобиль», а везёт логистика (`Deal::carried`): с 17.10.2026 ход наш (`SyncHandover::turn`),
 * но сделки, вошедшие в шаг раньше, так и просили менеджера «заберите» (18.10.2026: 6 сделок Совкомбанка с 10.10) — как
 * `turn`: ход наш, без срока, его просьба снимается.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Position::where('track', Track::Sale)->where(fn ($q) => $q->whereNull('waits_for')->orWhere('waits_for', '!=', WaitsFor::Us->value))
            ->whereHas('offer.deal', fn ($q) => $q->where('state', 'active'))->with(['stage.exits', 'offer.deal'])->get() as $position) {
            $deal = $position->offer?->deal;
            if (! $deal || ! $position->stage->isPickupStep() || ! $deal->setRelation('offer', $position->offer)->carried()) {
                continue;
            }
            $position->update(['waits_for' => WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);
            Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->delete();
        }
    }
};
