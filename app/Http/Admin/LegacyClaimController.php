<?php

namespace App\Http\Admin;

use App\Cars\CarText;
use App\Offers\Actions\AddLegacyClaims;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Http\Request;

/**
 * «Из таблицы Кати» (10.10.2026): вставка номеров убытка, «Завести» у незаведённого и «Убрать» из списка. Вкладка сама —
 * `OfferController` с `preset=legacy`, черновики туда кладёт номер (`LegacyClaim`).
 */
final class LegacyClaimController
{
    public function store(Request $request, AddLegacyClaims $add)
    {
        $text = (string) $request->validate(['claims' => ['required', 'string', 'max:20000']])['claims'];
        $result = $add($text, $request->user());
        $row = fn (Offer $o) => ['number' => $o->number, 'title' => $o->titleWithYear(), 'vendor_id' => $o->vendor_id, 'claim_ref' => $o->claim_ref, 'manager' => $o->deal?->buyer?->name];

        return redirect('/?preset=legacy')->with('toast', $result->summary())
            ->with('legacy.deals', array_map($row, $result->deals))
            ->with('legacy.published', array_map($row, $result->published));
    }

    /** Завести черновик с номером из списка — и сразу в редактор; архивное по тому же номеру возвращается в черновики. */
    public function create(Request $request, LegacyClaim $claim, CreateOffer $create, ChangeOfferState $change)
    {
        $offer = $claim->offer();
        if ($offer && in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true)) {
            $offer = $change($offer, OfferState::Draft, $request->user());
        }
        $offer ??= $create($request->user(), array_filter(['claim_ref' => $claim->claim_ref, 'vendor_id' => CarText::vendorOf($claim->claim_ref)]));

        return redirect("/offers/{$offer->number}");
    }

    /** Убрать номер из списка: черновик возвращается в свою рабочую вкладку. */
    public function destroy(Request $request, LegacyClaim $claim)
    {
        $offer = $claim->offer();
        $claim->delete();
        if ($offer && $request->header('Turbo-Frame') === 'detail') {
            return redirect("/offers/{$offer->number}")->with('toast', 'Возвращено в черновики')->with(OfferController::gone($request, $offer));
        }

        return back(fallback: '/?preset=legacy')->with('toast', $claim->claim_ref.' убран из списка');
    }
}
