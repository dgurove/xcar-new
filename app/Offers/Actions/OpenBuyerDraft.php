<?php

namespace App\Offers\Actions;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Новый покупатель плиткой вида (21.11.2026; с 26.11.2026 — и когда покупатель уже выбран, и сотрудником в CRM, владелец: «когда
 * прикрепили покупателя, его невозможно убрать»): черновик контрагента у договора (`draft_party_id`), без аккаунта; «Сохранить» в
 * шторке «Новый покупатель» заводит покупателя менеджера и ставит его в договор (`SaveDealContract`, `buyer_id = draft`). Передумал
 * вид или нажал дважды — тот же черновик. Покупатель уже выбран, а договор подписан или по счёту платили — нельзя (`Deal::buyerLocked`).
 */
final class OpenBuyerDraft
{
    public function __invoke(Deal $deal, string $kind): void
    {
        if ($deal->contract?->buyer_user_id && ($why = $deal->buyerLocked())) {
            throw ValidationException::withMessages(['buyer_id' => $why]);
        }
        $as = match ($kind) {
            'ip' => PartyKind::Entrepreneur, 'company' => PartyKind::Company, default => PartyKind::Person,
        };
        $contract = DealContract::for($deal);
        $draft = $contract->draftParty;
        if ($draft && ! User::where('party_id', $draft->id)->exists()) {
            $draft->update(['kind' => $as]);
        } else {
            $contract->update(['draft_party_id' => Party::create(['kind' => $as, 'name' => ''])->id]);
        }
    }
}
