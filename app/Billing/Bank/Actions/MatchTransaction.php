<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Actions\RecordPayment;
use App\Billing\Bank\Transaction;
use App\Billing\Events\PaymentConfirmed;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\Robot;
use App\Billing\Seller;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Входящее поступление → оплата счёта. Сам узнаёт только наверняка: номер нашего счёта в назначении
 * («по счёту № 12 от 28.09.2026») и сумма не больше остатка, ИНН плательщика не спорит с контрагентом счёта;
 * без номера — если ровно один открытый счёт этого ИНН ждёт ровно эту сумму. Перечисления ЮKassa счёт не закрывают:
 * это уже учтённые оплаты по ссылкам, их сверяет `ReconcilePayout`. Заявка менеджера на ту же сумму подтверждается, второй оплаты нет.
 * Руками (`$invoice`) — сотрудник выбрал счёт сам. Выписка — расчётный счёт ПРАЙМ: закрывает только счета ПРАЙМ
 * (у ИП парковки другой банк и свой ряд номеров, его оплаты отмечают руками на парковке).
 */
final class MatchTransaction
{
    /** Чей расчётный счёт в выписке. */
    private const SELLER = Seller::Prime;

    public function __construct(private RecordPayment $record, private ReconcilePayout $payout) {}

    public function __invoke(Transaction $tx, ?Invoice $invoice = null, ?User $by = null): Transaction
    {
        if (! $tx->isIncoming() || $tx->state === Transaction::MATCHED) {
            return $tx;
        }
        if (! $invoice && self::isPayout($tx)) {
            return $tx->state === Transaction::IGNORED ? $tx : ($this->payout)($tx);
        }
        $invoice ??= $this->guess($tx);
        if (! $invoice) {
            return $tx;
        }
        $manual = $by !== null;
        $by ??= Robot::user();

        $confirmed = DB::transaction(function () use ($tx, $invoice, $by, $manual) {
            $tx = Transaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();
            if ($tx->state === Transaction::MATCHED) {
                return null;
            }
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->seller !== self::SELLER) {
                if ($manual) {
                    throw ValidationException::withMessages(['invoice' => 'Счёт '.$invoice->label().' выставлен от '.$invoice->seller->party()->name.', на счёт ПРАЙМ его оплата не приходит']);
                }

                return null;
            }
            if ($invoice->state !== InvoiceState::Issued || $invoice->isOwed() || $tx->amount > $invoice->remaining() + 0.005) {
                if ($manual) {
                    throw ValidationException::withMessages(['invoice' => 'Поступление больше остатка счёта '.$invoice->label()]);
                }

                return null;
            }
            $claim = $invoice->claims()->get()->first(fn (Payment $p) => abs($p->amount - $tx->amount) < 0.005);
            $payment = ($this->record)($invoice, $by, $tx->amount, $tx->booked_at, PaymentSource::Bank, $tx->doc_number,
                'из выписки'.($tx->counterparty ? ', '.$tx->counterparty : ''), $claim);
            $tx->update(['state' => Transaction::MATCHED, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'note' => null,
                'decided_by' => $manual ? $by->id : null, 'decided_at' => now()]);

            return [$payment, $by];
        });
        if ($confirmed) {
            PaymentConfirmed::dispatch(...$confirmed);
        }

        return $tx->fresh();
    }

    public static function isPayout(Transaction $tx): bool
    {
        return $tx->counterparty_inn && $tx->counterparty_inn === config('xcar.yookassa.payout_inn');
    }

    /** Счёт, который это поступление закрывает наверняка; сомнение — null, решит человек. */
    public function guess(Transaction $tx): ?Invoice
    {
        $open = fn () => Invoice::ofSeller(self::SELLER)->where('direction', 'issued')->where('state', InvoiceState::Issued)->with('party');
        $fits = fn (Invoice $i) => $tx->amount <= $i->remaining() + 0.005 && (! $tx->counterparty_inn || ! $i->party->inn || $i->party->inn === $tx->counterparty_inn);

        [$numbers, $year] = self::numbers((string) $tx->purpose);
        if ($numbers) {
            $found = $open()->whereIn('number', $numbers)->where('year', $year ?? $tx->booked_at->year)->get()->filter($fits);
            if ($found->count() === 1) {
                return $found->first();
            }
            if ($found->isEmpty() && ! $year) {
                // Счёт прошлого года, оплаченный в январе.
                $found = $open()->whereIn('number', $numbers)->where('year', $tx->booked_at->year - 1)->get()->filter($fits);
                if ($found->count() === 1) {
                    return $found->first();
                }
            }
        }
        if ($tx->counterparty_inn) {
            $same = $open()->whereHas('party', fn ($p) => $p->where('inn', $tx->counterparty_inn))->get()
                ->filter(fn (Invoice $i) => abs($i->remaining() - $tx->amount) < 0.005);

            return $same->count() === 1 ? $same->first() : null;
        }

        return null;
    }

    /**
     * Номера счетов из назначения: «по счёту № 12», «сч. 12», «счет №12/2026», «оплата счета N 12 от 28.09.2026».
     * «сч» — только отдельным словом (не «расчёт», не «р/сч»), счёт-фактура не в счёт, номер не часть длинного числа.
     *
     * @return array{list<int>, ?int}
     */
    public static function numbers(string $purpose): array
    {
        $numbers = [];
        preg_match_all('/(?<![\p{L}\/])сч(?:[её]т[ау]?|\.)?(?![\p{L}-])(?:\s+на\s+оплату)?\s*(?:№|N|No|#)?\s*(\d{1,6})(?!\d|[.,]\d)(?:\s*\/\s*(20\d\d))?/iu', $purpose, $m, PREG_SET_ORDER);
        $year = null;
        foreach ($m as $hit) {
            $numbers[] = (int) $hit[1];
            $year ??= isset($hit[2]) && $hit[2] !== '' ? (int) $hit[2] : null;
        }
        if (! $year && preg_match('/от\s+\d{1,2}[.\/]\d{1,2}[.\/](20\d\d)/u', $purpose, $d)) {
            $year = (int) $d[1];
        }

        return [array_values(array_unique($numbers)), $year];
    }

    /** Открытые счета, похожие на это поступление, — для выбора руками: сначала по ИНН и сумме. */
    public static function suggestions(Transaction $tx): Collection
    {
        [$numbers] = self::numbers((string) $tx->purpose);

        return Invoice::ofSeller(self::SELLER)->where('direction', 'issued')->where('state', InvoiceState::Issued)->with(['party', 'deal.offer.brand', 'deal.offer.model', 'vehicle.brand', 'vehicle.model'])
            ->latest('issued_at')->limit(200)->get()
            ->filter(fn (Invoice $i) => $i->remaining() + 0.005 >= $tx->amount)
            ->sortByDesc(fn (Invoice $i) => ($tx->counterparty_inn && $i->party->inn === $tx->counterparty_inn ? 4 : 0)
                + (abs($i->remaining() - $tx->amount) < 0.005 ? 2 : 0) + (in_array($i->number, $numbers, true) ? 1 : 0))
            ->values();
    }
}
