<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Events\AgentFeeDue;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\WorkDays;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;

/**
 * Вознаграждение менеджера стало долгом: `owed`-счёт его контрагенту на сумму
 * вознаграждения со сроком в пять рабочих дней. Реквизитов может не быть — долг
 * от этого не исчезает, а кабинет и CRM просят их указать.
 */
final class IssueAgentFee
{
    public const DAYS = 5;

    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(Deal $deal, User $by): ?Invoice
    {
        if (! $deal->commission || $deal->withholds() || ! $deal->buyer || $deal->agentFee()->exists()) {
            return null;
        }
        $party = Party::forUser($deal->buyer);
        $offer = $deal->offer;
        $fee = ($this->issue)($party, $by, 'owed', ChargeKind::AgentFee, WorkDays::add(now(), self::DAYS), false,
            lines: [['title' => 'Агентское вознаграждение, '.$offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => (float) $deal->commission, 'kind' => ChargeKind::AgentFee->value]],
            dealId: $deal->id, offerId: $offer->id);
        $offer->log(OfferEventType::Note, $by, ['text' => 'Вознаграждение '.Money::rub($deal->commission).' к выплате — '.$party->name]);
        AgentFeeDue::dispatch($fee);

        return $fee;
    }
}
