<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Offers\DealState;

/**
 * Доли счёта в «Оплатах» сотрудника (23.11.2026, начальник: «комиссия включена в стоимость авто, и всё равно она должна
 * выделяться из общей суммы — Рено 1 450 000, а там 100 000 комиссии»; владелец: «одна часть — в ДКП, другая — в комиссиях»).
 * Счёт за ТС ПРАЙМ / Р.О.С. — закупочная и подбор одной суммой (`DealContract::priceFor`), сам счёт и PDF не делятся (бухгалтер,
 * 18.11.2026: одной строкой) — делится только строка «Оплат»: машина (до закупочной сделки) — в «ДКП», остальное — подбор в
 * «Комиссиях». Неполная оплата — сначала машина (владелец), подбор закрывается последним. Ручная ссылка, засчитанная в сделку
 * ПРАЙМ / Р.О.С., — так же: что ещё не легло в счета сделки, — машиной до закупочной, остальное — подбором.
 * SQL-двойник правила для счёта — `MoneyBook::splitSql`. Экран менеджера не делит (владелец: «у менеджера не надо»).
 */
final class InvoiceSplit
{
    /** Счёт за ТС ПРАЙМ / Р.О.С. с подбором внутри: сумма больше закупочной сделки. */
    public static function splits(Invoice $i, ?Deal $deal): bool
    {
        return $i->kind === ChargeKind::Sale && in_array($i->seller, [Seller::Prime, Seller::Ros], true)
            && $deal?->cost !== null && (float) $i->total > (float) $deal->cost + 0.005;
    }

    /**
     * [сумма, оплачено] счёта сделки в разделе (`dkp` — машина, `fees` — подбор). Счёт без подбора внутри — целиком.
     *
     * @return array{0: float, 1: float}
     */
    public static function part(Invoice $i, ?Deal $deal, string $tab): array
    {
        $total = (float) $i->total;
        $paid = (float) $i->paid;
        if (! self::splits($i, $deal)) {
            return [$total, $paid];
        }
        $car = (float) $deal->cost;

        return $tab === 'dkp'
            ? [$car, min($paid, $car)]
            : [round($total - $car, 2), round(max(0.0, $paid - $car), 2)];
    }

    /**
     * [сумма, оплачено] ручной ссылки в разделе: засчитанное в счета сделки (`credited`) — уже в их строках; остальное — машиной
     * на `$car` (доля, что ей досталась из ненабранной закупочной, `carShares`), сверх — подбором. Без доли — всё «Комиссиям».
     *
     * @return array{0: float, 1: float}
     */
    public static function servicePart(Invoice $i, string $tab, float $car = 0.0): array
    {
        $credited = (float) $i->getAttribute('credited');
        $rest = max(0.0, (float) $i->total - $credited);
        $restPaid = max(0.0, (float) $i->paid - $credited);
        $car = min($rest, $car);

        return $tab === 'dkp'
            ? [round($car, 2), round(min($restPaid, $car), 2)]
            : [round($rest - $car, 2), round(max(0.0, $restPaid - $car), 2)];
    }

    /**
     * Сколько машины у каждой ручной ссылки, засчитанной в сделку ПРАЙМ / Р.О.С. (не отменённую, с закупочной): закупочная минус
     * то, что уже пришло в счета за ТС (засчитанные ссылки — там же), раздаётся незасчитанным остаткам ссылок: сначала оплаченным, потом по порядку. Все
     * ссылки этих сделок — одним запросом, чтобы доля не зависела от того, открытые или оплаченные строки сейчас рисуются.
     *
     * @param  iterable<Invoice>  $links
     * @return array<int, float> id ссылки => машина
     */
    public static function carShares(iterable $links): array
    {
        $deals = collect($links)->map(fn (Invoice $i) => $i->creditDeal)->filter(fn (?Deal $d) => $d && $d->state !== DealState::Cancelled
            && $d->isPrime() && $d->cost !== null)->unique('id')->keyBy('id');
        if ($deals->isEmpty()) {
            return [];
        }
        $received = Invoice::whereIn('deal_id', $deals->keys())->where('direction', 'issued')->where('kind', ChargeKind::Sale->value)
            ->whereIn('seller', [Seller::Prime->value, Seller::Ros->value])->where('state', '!=', InvoiceState::Void->value)
            ->selectRaw('deal_id, sum(paid) as paid')->groupBy('deal_id')->pluck('paid', 'deal_id');
        $all = Invoice::whereIn('credit_deal_id', $deals->keys())->where('kind', ChargeKind::Service->value)->where('direction', 'issued')
            ->where('state', '!=', InvoiceState::Void->value)->select('id', 'credit_deal_id', 'total', 'state')
            ->selectRaw("coalesce((select sum(cp.amount) from billing_payments cp where cp.credit_invoice_id = billing_invoices.id
                and cp.source = 'credit' and cp.state = 'confirmed' and cp.voided_at is null), 0) as credited")
            // Машина — сначала оплаченным ссылкам: деньги пришли — они и за машину; ждущая ссылка не забирает её себе.
            ->orderByRaw("state = 'paid' desc")->orderBy('id')->get();
        $shares = [];
        foreach ($all->groupBy('credit_deal_id') as $dealId => $own) {
            $left = max(0.0, (float) $deals[$dealId]->cost - min((float) $deals[$dealId]->cost, (float) ($received[$dealId] ?? 0)));
            foreach ($own as $link) {
                $car = min($left, max(0.0, (float) $link->total - (float) $link->credited));
                $shares[$link->id] = $car;
                $left -= $car;
            }
        }

        return $shares;
    }
}
