<?php

namespace App\Http\Admin;

use App\Offers\Actions\AskEstimates;
use App\Offers\Actions\CloseEstimates;
use App\Offers\Actions\RejectEstimate;
use App\Offers\Actions\TakeEstimate;
use App\Offers\Estimate;
use App\Offers\Offer;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Оценка менеджером в окошке «Оценить» вкладки «Без продажной цены» (11.10.2026, начальник): «Отдать менеджеру на оценку»,
 * под полем цены — кто оценил («Взять» ставит цену продажи, как «Оценить»; «Отклонить» — оценивает снова) и кто ещё думает
 * («×» снимает просьбу).
 */
final class OfferEstimateController
{
    public function store(Request $request, Offer $offer, AskEstimates $ask)
    {
        $ids = array_map('intval', (array) $request->validate(['managers' => ['required', 'array', 'min:1'], 'managers.*' => ['integer']])['managers']);
        $created = $ask($offer, User::withRole(Role::Manager)->whereIn('id', $ids)->get(), $request->user());

        return back()->with('toast', $created->isEmpty() ? 'Уже на оценке' : 'Отдано на оценку: '.$created->map(fn (Estimate $e) => $e->user->shortName())->implode(', '));
    }

    /** «Взять» — как «Оценить»: строка уходит в «Оцененные», карточка — к следующей без цены. */
    public function take(Request $request, Offer $offer, Estimate $estimate, TakeEstimate $take)
    {
        abort_unless($estimate->offer_id === $offer->id, 404);
        $take($estimate, $request->user());
        // Корректировка: строка остаётся в «Оцененных» с новой ценой — карточка просто перечитывается.
        if ($estimate->isCorrection()) {
            return back()->with('toast', 'Цена продажи: '.Money::rub($estimate->amount))->with('detail-counts', OfferController::counts($request->user()));
        }

        return back()->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', OfferController::counts($request->user()))
            ->with('toast', 'Оценено: '.Money::rub($estimate->amount));
    }

    public function reject(Request $request, Offer $offer, Estimate $estimate, RejectEstimate $reject)
    {
        abort_unless($estimate->offer_id === $offer->id, 404);
        $reject($estimate, $request->user());

        return back()->with('toast', 'Оценка отклонена')->with('detail-counts', OfferController::counts($request->user()));
    }

    /** «Оставить свою» у корректировки: цена админа остаётся, просьбы закрываются. */
    public function keep(Request $request, Offer $offer, CloseEstimates $close)
    {
        $close($offer, $request->user(), keep: true);

        return back()->with('toast', 'Цена '.Money::rub((int) $offer->asking_price).' остаётся')
            ->with('detail-counts', OfferController::counts($request->user()));
    }

    public function destroy(Request $request, Offer $offer, Estimate $estimate, CloseEstimates $close)
    {
        abort_unless($estimate->offer_id === $offer->id, 404);
        $close($offer, $request->user(), [$estimate->id]);

        return back()->with('toast', 'Снято с оценки');
    }
}
