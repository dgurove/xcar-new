<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\Transaction;
use App\Support\Nav;
use App\Users\User;

/** «Не наше»: поступление не к счёту (возврат, займ, перевод между счетами) — уходит из «Не привязаны». Можно вернуть. */
final class IgnoreTransaction
{
    public function __invoke(Transaction $tx, User $by, bool $ignore = true, ?string $note = null): Transaction
    {
        if ($tx->state === Transaction::MATCHED || ! $tx->isIncoming()) {
            return $tx;
        }
        $tx->update($ignore
            ? ['state' => Transaction::IGNORED, 'note' => $note ?: 'Не наше', 'decided_by' => $by->id, 'decided_at' => now()]
            : ['state' => Transaction::UNMATCHED, 'note' => null, 'decided_by' => null, 'decided_at' => null]);
        Nav::forgetStaffCounts();

        return $tx;
    }
}
