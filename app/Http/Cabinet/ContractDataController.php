<?php

namespace App\Http\Cabinet;

use App\Billing\Actions\SaveBuyerData;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PartyRules;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Данные для договора у самого покупателя (19.10.2026): менеджер выбрал его покупателем по ДКП или позвал ссылкой из
 * сделки — паспорт и скан двух страниц покупатель вносит сам, в кабинете, а не пересылает менеджеру фото в мессенджере.
 * Сохранил — счёт и договор его сделок встают сами (`PassportScanController::resyncFor`), менеджеру «ДКП готов».
 */
class ContractDataController
{
    /** Покупатель по договору хотя бы одной идущей сделки — пункт кабинета и экран есть только ему. */
    public static function opensFor(User $user): bool
    {
        return ! $user->isManager() && DealContract::where('buyer_user_id', $user->id)->whereHas('deal', fn ($q) => $q->where('state', DealState::Active))->exists();
    }

    public function show(Request $request)
    {
        abort_unless(self::opensFor($me = $request->user()), 404);
        $party = Party::forUser($me);
        if ($party->wasRecentlyCreated || ! $party->kind) {
            $party->forceFill(['kind' => PartyKind::Person, 'name' => $party->name ?: $me->name])->save();
        }

        return view('cabinet.contract-data', ['party' => $party]);
    }

    public function update(Request $request, SyncDealInvoices $invoices, SaveBuyerData $save)
    {
        abort_unless(self::opensFor($me = $request->user()), 404);
        PartyRules::squeeze($request);
        $data = $request->validate(PartyRules::passport('buyer'), [], PartyRules::buyerLabels())['buyer'];
        $party = $save(Party::forUser($me), $data);
        PassportScanController::resyncFor($me, $me, $invoices);

        $gap = $party->fresh()->buyerGap();

        return back()->with('toast', match (true) {
            $party->readyAsBuyer() => 'Данные для договора сохранены',
            $gap === 'нужен банк ИП' => 'Сохранено, впишите банк',
            default => 'Сохранено, приложите скан паспорта',
        });
    }
}
