<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\Seller;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Счёт по сделке руками («Ещё счёт» в CRM): одной строкой — «Транспортное средство» или «Подбор ТС» на сумму, что вписал
 * сотрудник (форма подставляет цену минус вознаграждение менеджера, у подбора — подбор). Строки «Агентское вознаграждение»
 * и зачёта нет (18.11.2026: вознаграждения мы не выплачиваем, наш счёт — сразу за вычетом его).
 * НДС — ставкой продавца (`IssueInvoice`): в сумме, а у контрагента с «НДС сверху» — прибавлен к строке.
 */
final class IssueDealInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(Deal $deal, User $by, Party $party, ChargeKind $kind, float $amount, CarbonInterface $dueAt, ?string $notes = null, ?string $title = null): Invoice
    {
        $offer = $deal->offer;
        $car = $offer->titleWithYear();
        $title ??= match ($kind) {
            ChargeKind::Sale => 'Транспортное средство '.$car,
            ChargeKind::Selection => 'Подбор ТС '.$car,
            default => $car,
        };
        $lines = [['title' => $title, 'qty' => 1, 'unit' => 'pc', 'price' => round($amount, 2), 'kind' => $kind->value]];

        return DB::transaction(function () use ($deal, $by, $party, $kind, $dueAt, $notes, $lines, $offer) {
            // Счёт за машину — от продавца сделки (Альфа с НДС — Р.О.С.), подбор — продавцом услуг подбора сделки.
            $seller = match ($kind) {
                ChargeKind::Sale => $deal->seller(),
                ChargeKind::Selection => $deal->feeSeller(),
                default => Seller::Prime,
            };
            $invoice = ($this->issue)($party, $by, 'issued', $kind, $dueAt, lines: $lines, dealId: $deal->id, offerId: $offer->id, notes: $notes, seller: $seller);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Счёт '.$invoice->label().' на '.Money::rub($invoice->total).', платит '.$party->name]);

            return $invoice;
        });
    }
}
