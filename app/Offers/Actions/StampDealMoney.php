<?php

namespace App\Offers\Actions;

use App\Billing\Bank\Transaction;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\PaymentState;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Offers\DealState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Продано» и «Оплачено» сделки — из денег (14.11.2026, владелец: «почему нигде не проставлены даты — связь со СберБизнесом
 * же есть»; «дата — первого платежа»). Одна дверь: зовут оплата счёта (`StampPaidIn`), привязка строки выписки к машине
 * (`ImportStatement`, `bank:link`, `bank:history`).
 * — «Продано» (`DealDates::soldColumn`, через нас — `paid_in_at`) — первый день, когда за машину пришли деньги: оплаты счетов
 *   сделки и зачётов (`credit_deal_id`) и входящие выписки, привязанные к сделке (`CarMatch`);
 * — «Оплачено» (платим мы — `supplier_paid_at`) — первый исходящий платёж выписки по машине поставщику: ИНН вендора или
 *   «платим за поставщика» (`xcar.bank.supplier_inns`). Сделка на шаге нашей оплаты поставщику — шаг проходит сам
 *   (`SetDealDate`, «по выписке Сбера»).
 * Вписанное руками (`deals.dates_by_hand`) не трогается.
 */
final class StampDealMoney
{
    public function __construct(private SetDealDate $set) {}

    public function __invoke(Deal $deal): Deal
    {
        if (! in_array($deal->state, [DealState::Active, DealState::Done], true)) {
            return $deal;
        }
        $deal->loadMissing('offer.vendor.party');
        $hand = $deal->dates_by_hand ?? [];
        $sold = DealDates::soldColumn($deal);
        if ($sold === 'paid_in_at' && ! in_array('paid_in_at', $hand, true)) {
            $first = self::firstIn($deal);
            if ($first?->toDateString() !== $deal->paid_in_at?->toDateString()) {
                $deal->forceFill(['paid_in_at' => $first])->saveQuietly();
            }
        }
        $paid = DealDates::paidColumn($deal);
        if ($paid === 'supplier_paid_at' && ! in_array('supplier_paid_at', $hand, true) && ($tx = self::firstOut($deal))) {
            $at = Carbon::parse($tx->booked_at)->setTime(12, 0);
            if (! $deal->supplier_paid_at || $deal->supplier_paid_at->gt($at)) {
                // Сделка на шаге нашей оплаты — пройдёт (`SetDealDate`), иначе только дата.
                $deal = ($this->set)($deal, DealDates::PAID, $at, null, 'по выписке Сбера'.($tx->doc_number ? ', платёжка № '.$tx->doc_number : ''), $tx->doc_number);
            }
        }

        return $deal->fresh();
    }

    /** Первая оплата за машину: счета сделки и зачёты, входящие выписки этой сделки. */
    public static function firstIn(Deal $deal): ?Carbon
    {
        $invoices = Invoice::where('direction', 'issued')->where('kind', '!=', ChargeKind::Reward)
            ->where(fn ($q) => $q->where('deal_id', $deal->id)->orWhere('credit_deal_id', $deal->id))->pluck('id');
        $payments = $invoices->isEmpty() ? null : DB::table('billing_payments')->whereIn('invoice_id', $invoices)
            ->where('state', PaymentState::Confirmed->value)->min('paid_at');
        $bank = Transaction::where('deal_id', $deal->id)->where('direction', 'in')->min('booked_at');
        $first = collect([$payments, $bank])->filter()->map(fn ($d) => Carbon::parse($d))->min();

        return $first?->setTime(12, 0);
    }

    /** Первый наш платёж поставщику за эту машину из выписки. */
    public static function firstOut(Deal $deal): ?Transaction
    {
        $inns = array_keys(config('xcar.bank.supplier_inns', []));
        if ($inn = $deal->offer?->vendor?->party?->inn) {
            $inns[] = $inn;
        }

        return Transaction::where('deal_id', $deal->id)->where('direction', 'out')->whereIn('counterparty_inn', $inns)
            ->orderBy('booked_at')->orderBy('id')->first();
    }

    /** Все сделки, к которым привязана выписка или есть оплаты, — пересчёт (`bank:link`). */
    public static function dealsWithMoney(): Collection
    {
        return Deal::whereIn('state', [DealState::Active, DealState::Done])->where(fn ($q) => $q
            ->whereIn('id', Transaction::whereNotNull('deal_id')->select('deal_id'))
            ->orWhereIn('id', Invoice::whereNotNull('deal_id')->select('deal_id'))
            ->orWhereIn('id', Invoice::whereNotNull('credit_deal_id')->select('credit_deal_id')))->get();
    }
}
