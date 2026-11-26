<?php

namespace App\Offers\Listeners;

use App\Offers\Deal;
use App\Offers\Originals;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Вошли в шаг «Оригиналы» (26.11.2026): у сделки документы, что должны дойти (`Originals::ensure`, набор — `Originals::kit`), и
 * менеджеру сообщение в чат, если что-то у него (`Originals::nudge`).
 */
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
            // Сообщение менеджеру — только при первом входе и после записи шага (ревью 26.11.2026): сбой чата не откатит
            // «Договор подписан», возврат на шаг не пришлёт его снова. Ждать нечего (покупатель платит не нам) — шаг проходит сам.
            if (Originals::ensure($deal)) {
                $id = $deal->id;
                $by = $e->by;
                DB::afterCommit(fn () => rescue(function () use ($id, $by) {
                    $deal = Deal::find($id);
                    if ($deal && ! Originals::advance($deal, $by)) {
                        Originals::nudge($deal, $by);
                    }
                }, report: true));
            }
        }
    }
}
