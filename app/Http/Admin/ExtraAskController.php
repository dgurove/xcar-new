<?php

namespace App\Http\Admin;

use App\Offers\Actions\SettleExtraAsk;
use App\Offers\ExtraAsk;
use App\Offers\Offer;
use Illuminate\Http\Request;

/**
 * Доп. просьба в CRM (18.11.2026, `Offers\ExtraAsk`): «Вопрос не решён» — снова ждём поставщика, «Ответили не письмом», решение за
 * менеджера («Берёт» / «Отказался»). Дверь — работа со сделкой (`canEditDeals`).
 */
final class ExtraAskController
{
    public function reopen(Request $request, Offer $offer, ExtraAsk $ask, SettleExtraAsk $settle)
    {
        abort_unless($ask->offer_id === $offer->id, 404);
        $settle->reopen($ask, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Снова ждём ответа поставщика');
    }

    public function phone(Request $request, Offer $offer, ExtraAsk $ask, SettleExtraAsk $settle)
    {
        abort_unless($ask->offer_id === $offer->id, 404);
        $settle->byPhone($ask, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', $ask->fresh()->isOpen() ? 'Ждём решения менеджера' : 'Просьба решена');
    }

    public function decide(Request $request, Offer $offer, SettleExtraAsk $settle)
    {
        $data = $request->validate(['decision' => ['required', 'in:'.ExtraAsk::TAKE.','.ExtraAsk::DROP]]);
        $deal = $offer->deal()->first();
        abort_unless($deal?->isActive(), 404);
        $settle->decide($deal, $data['decision'], $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', $data['decision'] === ExtraAsk::TAKE ? 'Менеджер берёт — сделка идёт дальше' : 'Сделка отменена');
    }
}
