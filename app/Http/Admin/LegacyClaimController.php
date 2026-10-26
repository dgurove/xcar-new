<?php

namespace App\Http\Admin;

use App\Offers\Actions\AddLegacyClaims;
use App\Offers\Actions\DeleteDraft;
use App\Offers\Actions\RemoveLegacyClaim;
use App\Offers\Intake\DraftSource;
use App\Offers\Intake\OpenDraft;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
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

    /**
     * Завести черновик с номером из списка — и сразу в редактор (`OpenDraft`: вендор по виду номера, архивное и отменённое
     * по тому же номеру — снова черновик).
     */
    public function create(Request $request, LegacyClaim $claim, OpenDraft $open)
    {
        [$offer, $outcome] = $open(new DraftSource('legacy', $claim->claim_ref, created: ['legacy' => true]), $request->user());
        if ($outcome === OpenDraft::CREATED) {
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
