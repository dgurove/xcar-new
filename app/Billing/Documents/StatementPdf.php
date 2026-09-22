<?php

namespace App\Billing\Documents;

use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Support\Pdf;
use App\Users\User;
use Carbon\CarbonInterface;

/**
 * Акт сверки взаимных расчётов между нами и контрагентом менеджера за период:
 * наши счета — дебет, его оплаты и удержания — кредит; наши обязательства по
 * вознаграждению — кредит, выплаты — дебет; сальдо на начало и конец.
 */
final class StatementPdf
{
    public function render(User $manager, CarbonInterface $from, CarbonInterface $to): string
    {
        return Pdf::render('billing.docs.statement', $this->data($manager, $from, $to) + ['pdf' => true]);
    }

    public function data(User $manager, CarbonInterface $from, CarbonInterface $to): array
    {
        $ledger = new ManagerLedger($manager);
        $all = $ledger->history()->reverse()->values();
        $entry = fn (array $r) => match ($r['kind']) {
            'invoice' => ['debit' => $r['amount'], 'credit' => 0],
            'paid', 'offset', 'fee' => ['debit' => 0, 'credit' => $r['amount']],
            'payout' => ['debit' => $r['amount'], 'credit' => 0],
            default => null,
        };
        $opening = 0.0;
        $rows = [];
        foreach ($all as $r) {
            $e = $entry($r);
            if (! $e) {
                continue;
            }
            if ($r['at']->lt($from)) {
                $opening += $e['debit'] - $e['credit'];
            } elseif ($r['at']->lte($to)) {
                $rows[] = $r + $e;
            }
        }
        $closing = $opening + array_sum(array_column($rows, 'debit')) - array_sum(array_column($rows, 'credit'));

        return ['self' => Party::self(), 'party' => Party::forUser($manager, false), 'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows, 'closing' => $closing];
    }
}
