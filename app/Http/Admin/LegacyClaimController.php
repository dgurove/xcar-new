<?php

namespace App\Http\Admin;

use App\Cars\CarText;
use App\Offers\Actions\AddLegacyClaims;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\DeleteDraft;
use App\Offers\Actions\RemoveLegacyClaim;
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
        // Отменённое (отказ поставщика) в черновик напрямую не ходит — только через архив (`OfferState::allows`).
        if ($offer?->state === OfferState::Cancelled) {
            $offer = $change($offer, OfferState::Archived, $request->user());
        }
        if ($offer?->state === OfferState::Archived) {
            $offer = $change($offer, OfferState::Draft, $request->user());
        }
        if (! $offer) {
            $offer = $create($request->user(), array_filter(['claim_ref' => $claim->claim_ref, 'vendor_id' => CarText::vendorOf($claim->claim_ref)]));
            // До первого «Сохранить» внизу редактора — «Отменить» (drop).
            session()->put("legacy-draft.{$offer->id}", $claim->id);
        }

        return redirect("/offers/{$offer->number}");
    }

    /** «Отменить» в редакторе после «Завести»: черновик удаляется, номер снова в «Не заведены». */
    public function drop(Request $request, Offer $offer, DeleteDraft $delete)
    {
        abort_unless(session()->has("legacy-draft.{$offer->id}") && $offer->isDeletableBy($request->user()), 404);
        $delete($offer, $request->user());
        session()->forget("legacy-draft.{$offer->id}");

        return redirect('/?preset=legacy')->with('toast', $offer->claim_ref.' снова в «Не заведены»');
    }

    /** Убрать номер из списка: черновик возвращается в свою рабочую вкладку. */
    public function destroy(Request $request, LegacyClaim $claim, RemoveLegacyClaim $remove)
    {
        $offer = $remove($claim);
        if ($offer && $request->header('Turbo-Frame') === 'detail') {
            return redirect("/offers/{$offer->number}")->with('toast', 'Возвращено в черновики')->with(OfferController::gone($request, $offer));
        }

        return back(fallback: '/?preset=legacy')->with('toast', $claim->claim_ref.' убран из списка');
    }
}
