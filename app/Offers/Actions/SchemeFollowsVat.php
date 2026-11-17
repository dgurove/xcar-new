<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Users\User;

/**
 * Схема сделки идёт за НДС предложения сама (17.11.2026, владелец: «мы договаривались, что если машина от Альфы с НДС, то
 * платёж будет не от покупателя, а от ООО «Р.О.С.»; я вообще не понял, как это указать в CRM, остальные тем более»). Поставили
 * «С НДС» у машины в сделке — «Через Р.О.С.» (покупатель платит Р.О.С., Р.О.С. — поставщику), сняли — схема по умолчанию
 * (`DealScheme::forOffer`: у Альфы «Напрямую», у остальных ПРАЙМ). Только пока по сделке не платили (`moneyEditable`), только
 * между «Напрямую», ПРАЙМ и Р.О.С. — ДКП собственника не трогаем. Вручную по-прежнему админ строкой «Покупатель платит»
 * (`ChangeDealScheme`, «Точно?» — `DealScheme::unusual`).
 */
final class SchemeFollowsVat
{
    private const FOLLOWING = [DealScheme::Insurer, DealScheme::Prime, DealScheme::Ros];

    public function __construct(private ChangeDealScheme $change) {}

    /** @return DealScheme|null новая схема, если сменилась */
    public function __invoke(Offer $offer, ?User $by): ?DealScheme
    {
        $deal = Deal::where('offer_id', $offer->id)->active()->latest('id')->first();
        if (! $deal || ! $by || $deal->isGarage() || $deal->isAuction() || ! $deal->moneyEditable()) {
            return null;
        }
        $deal->setRelation('offer', $offer);
        $now = $deal->schemeOf();
        $want = self::want($offer, $now);
        if (! $want || $want === $now || ! in_array($now, self::FOLLOWING, true) || ! in_array($want, DealScheme::casesFor($offer->vendor, $offer), true)) {
            return null;
        }
        ($this->change)($deal, $want, $by);

        return $want;
    }

    /** С НДС — Р.О.С. (кроме опта); без НДС — схема по умолчанию, но только если сейчас Р.О.С. (выбор «Напрямую» или ПРАЙМ без НДС не трогаем). */
    public static function want(Offer $offer, DealScheme $now): ?DealScheme
    {
        // С НДС — Р.О.С., где это умолчание поставщика (опт Каркаде «с НДС» всегда — там ПРАЙМ, его не трогаем).
        if ($offer->prices_include_vat) {
            return DealScheme::forOffer($offer) === DealScheme::Ros ? DealScheme::Ros : null;
        }

        return $now === DealScheme::Ros ? DealScheme::forOffer($offer) : null;
    }
}
