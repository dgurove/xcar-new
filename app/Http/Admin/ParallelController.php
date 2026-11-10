<?php

namespace App\Http\Admin;

use App\Mail\LetterPrompt;
use App\Offers\Actions\CloseParallels;
use App\Offers\Actions\TakeParallel;
use App\Offers\Deal;
use App\Workflow\Track;
use Illuminate\Http\Request;

/**
 * Блок «Параллельно» сделки в CRM (07.11.2026, `admin/offers/parallels`): «Взять в работу» — сделка переключается на этого
 * менеджера и его реквизиты (`TakeParallel`); шаг стоит на письме с реквизитами, а письмо ещё не ушло — окно письма сразу,
 * как после «Принять» (`LetterPrompt`). «Снять» — закрыть одну параллельную (`CloseParallels::one`).
 */
class ParallelController
{
    public function take(Request $request, Deal $deal, TakeParallel $take)
    {
        abort_unless($deal->isParallel(), 404);
        ['deal' => $deal, 'reissue' => $reissue] = $take($deal, $request->user());
        $offer = $deal->offer->fresh();
        $position = $offer->position(Track::Sale);
        if ($position?->stage->isRequisitesStep() && in_array($position->letterStatus(), ['unsent', 'failed'], true) && ($url = $offer->vendorLetterUrl($position->stage))) {
            LetterPrompt::ask($request->user(), $offer, $url.'&then=close');
        }

        return back()->with('toast', 'В работу: '.$deal->buyer?->shortName().($reissue ? ', письмо «перевыставить счёт»' : ''));
    }

    public function drop(Request $request, Deal $deal, CloseParallels $close)
    {
        abort_unless($deal->isParallel(), 404);
        $close->one($deal->load('offer'), $request->user(), 'снял '.$request->user()->shortName());

        return back()->with('toast', 'Параллельная снята: '.$deal->buyer?->shortName());
    }
}
