<?php

namespace App\Offers\Actions;

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
        if (! DealScheme::choiceFor($deal->offer->vendor?->deal_format) || ! in_array($scheme, DealScheme::casesFor($deal->offer->vendor, $deal->offer), true)) {
            throw ValidationException::withMessages(['scheme' => 'Эту схему у этого поставщика выбрать нельзя']);
        }
        if (! $ok && ($ask = DealScheme::unusual($deal->offer, $scheme))) {
            throw ValidationException::withMessages(['scheme' => $ask, 'scheme_check' => '1']);
        }
        $was = $deal->schemeOf();
        if ($was === $scheme) {
            return $deal;
        }
        ($this->money)($deal, $by, $deal->commission, $deal->commission_mode, $scheme, $deal->share, 'Схема: '.$was->label().' → '.$scheme->label());
        $deal = $deal->fresh(['offer', 'contract.buyer.party']);
        $this->advance->rehome($deal, $by);

        return $deal;
    }
}
