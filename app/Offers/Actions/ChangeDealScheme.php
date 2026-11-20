<?php

namespace App\Offers\Actions;

use App\Offers\OfferEventType;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Сменить схему сделки строкой «Схема» в CRM (03.11.2026, владелец: у Альфы стоял «ПРАЙМ по счёту» с 06.10, «должна быть
 * возможность поменять удобно и посмотреть, кто это выбрал»). Деньги и счета — прежней дверью `UpdateDealMoney`; в «Истории» —
 * «Схема: было → стало»; кто и когда — `deals.scheme_by|scheme_at` (`Deal::booted`). Шаг реквизитов Альфы встаёт по новой
 * схеме (`AdvanceOnBuyerData::rehome`).
 */
final class ChangeDealScheme
{
    public function __construct(private UpdateDealMoney $money, private AdvanceOnBuyerData $advance) {}

    public function __invoke(Deal $deal, DealScheme $scheme, User $by, bool $ok = true): Deal
    {
        $deal->loadMissing('offer.vendor');
        if (! in_array($scheme, DealScheme::casesFor($deal->offer->vendor, $deal->offer), true)) {
            throw ValidationException::withMessages(['scheme' => 'Эту схему у этого поставщика выбрать нельзя']);
        }
        if (! $ok && ($ask = DealScheme::unusual($deal->offer, $scheme))) {
            throw ValidationException::withMessages(['scheme' => $ask, 'scheme_check' => '1']);
        }
        $was = $deal->schemeOf();
        if ($was === $scheme) {
            return $deal;
        }
        // Покупателем было вписано наше юрлицо (Р.О.С. «покупателем», чтобы поставщик выставил счёт на Р.О.С., 17.11.2026) —
        // в новой схеме оно продавец, покупатель снимается: менеджер впишет настоящего. Плательщик-наше юрлицо — тоже.
        $contract = $deal->contract()->with('buyer.party')->first();
        if ($contract?->buyer?->party && ($contract->buyer->party->isOurs())) {
            $contract->forceFill(['buyer_user_id' => null, 'payer' => 'manager'])->save();
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Покупатель «'.$contract->buyer->name.'» снят: наше юрлицо не покупатель']);
        }
        if ($deal->payer?->isOurs()) {
            $deal->forceFill(['payer_party_id' => null])->save();
        }
        ($this->money)($deal->fresh(), $by, $deal->commission, $scheme, $deal->share, 'Схема: '.$was->label().' → '.$scheme->label());
        $deal = $deal->fresh(['offer', 'contract.buyer.party']);
        // «Через Р.О.С.» — с НДС (17.11.2026, владелец): цены машины встают «С НДС» сами (галки НДС в окне сделки нет); обратно
        // схема за НДС идёт сама (`SchemeFollowsVat`), здесь круга нет — схема уже Р.О.С.
        if ($scheme === DealScheme::Ros && ! $deal->offer->prices_include_vat) {
            app(UpdateOffer::class)($deal->offer, ['prices_include_vat' => true], $by, ['why' => 'Через Р.О.С. — с НДС']);
            $deal = $deal->fresh(['offer', 'contract.buyer.party']);
        }
        // Альфа ушла с Р.О.С. руками (20.11.2026, владелец: «убрать „с НДС“ — снова напрямую Альфе») — и наоборот: схема без
        // Р.О.С. у Альфы — без НДС, ставка и метка «С НДС» снимаются, в «Истории» строкой.
        if ($was === DealScheme::Ros && $scheme !== DealScheme::Ros && $deal->offer->vendor?->pay_before_pickup && (int) $deal->offer->supplier_vat > 0) {
            app(UpdateOffer::class)($deal->offer, ['supplier_vat' => 0, 'supplier_vat_source' => 'human', 'supplier_vat_at' => now()], $by, ['why' => 'Не через Р.О.С. — без НДС']);
            $deal = $deal->fresh(['offer', 'contract.buyer.party']);
        }
        $this->advance->rehome($deal, $by);
        // Сменился продавец (ПРАЙМ ↔ Р.О.С.) — номер договора прежний: очередь одна на обоих, выданный номер не меняется
        // (`ContractNumber`, 16.11.2026); счёт уже перевыставил `UpdateDealMoney` → `SyncDealInvoices`.

        return $deal;
    }
}
