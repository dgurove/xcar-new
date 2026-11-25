<?php

namespace App\Offers\Listeners;

use App\Offers\Originals;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/** Вошли в шаг «Оригиналы» (26.11.2026) — у сделки три документа, что должны дойти (`Originals::ensure`). */
final class OpenOriginals
{
    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'entered'];
    }

    public function entered(StageEntered $e): void
    {
        $deal = $e->deal ?? $e->offer->deal;
        if ($e->track === Track::Sale && $deal?->isActive() && Originals::applies($e->to)) {
            Originals::ensure($deal);
        }
    }
}
