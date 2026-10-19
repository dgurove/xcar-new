<?php

namespace App\Live;

use App\Offers\Deal;
use App\Offers\DealContract;

/**
 * Сделка поменялась, а машина нет (19.10.2026, владелец: «чтобы экран не обновлять»): файл шага, договор и его номер,
 * покупатель и паспорт, счёт выставлен или аннулирован, оплата отклонена. У всех, кто её видит, перечитываются: CRM —
 * редактор предложения и строка «Работы → Сделки» («Гаража»), менеджер — страница сделки (гаражная — машина в гараже),
 * список и «Оплаты», покупатель по договору — «Данные для договора». Одна дверь вместо путей в каждом действии; пачкой
 * экраны не дёргаются (`refreshSoon`), всё — после коммита (`Publisher`).
 */
final class RefreshDeal
{
    public function __construct(private Publisher $publish) {}

    public static function of(?Deal $deal): void
    {
        if ($deal) {
            app(self::class)($deal);
        }
    }

    public function __invoke(Deal $deal): void
    {
        $offer = $deal->offer;
        if (! $offer) {
            return;
        }
        $n = $offer->number;
        $garage = $deal->isGarage();
        $this->publish->refreshSoon(Topics::STAFF, ["/offers/{$n}", '/work/money']);
        $this->publish->rowsSoon(Topics::STAFF, $garage ? ['/work/garage' => [$n]] : ['/work/deals' => [$deal->id]]);
        if ($deal->buyer_id) {
            $this->publish->refreshSoon(Topics::user($deal->buyer_id), $garage
                ? ['/garage', "/garage/cars/{$n}", '/account/money']
                : ['/deals', "/deals/{$deal->id}", '/account/money']);
        }
        $buyer = DealContract::where('deal_id', $deal->id)->value('buyer_user_id');
        if ($buyer && $buyer !== $deal->buyer_id) {
            $this->publish->refreshSoon(Topics::user($buyer), ['/account/contract']);
        }
    }
}
