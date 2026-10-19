<?php

namespace App\Http\Cabinet;

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

    public function update(Request $request, SyncDealInvoices $invoices)
    {
        abort_unless(self::opensFor($me = $request->user()), 404);
        $data = $request->validate(PartyRules::passport('buyer'), [], ['buyer.name' => 'ФИО'])['buyer'];
        $party = Party::forUser($me);
        $company = ($data['kind'] ?? 'person') === 'company';
        $fields = $company ? ['name', 'inn', 'kpp', 'ogrn', 'legal_address', 'director'] : ['name', 'birth_at', 'birth_place', 'passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'reg_address'];
        $party->fill(['kind' => $company ? PartyKind::Company : PartyKind::Person] + array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, array_intersect_key($data, array_flip($fields))))->save();
        PassportScanController::resyncFor($me, $me, $invoices);

        return back()->with('toast', $party->fresh()->readyAsBuyer() ? 'Данные для договора сохранены' : 'Сохранено, приложите скан паспорта');
    }
}
