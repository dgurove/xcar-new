<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\CarMatch;
use App\Billing\Bank\Transaction;
use App\Offers\Actions\StampDealMoney;
use App\Offers\Deal;

/**
 * Строка выписки → машина по назначению (`CarMatch`) → её сделка: `deal_id`, чем узнали; даты сделки пересчитываются
 * (`StampDealMoney`). Входящая, уже привязанная к счёту сделки, — к этой сделке. Привязанную руками не перепривязываем.
 */
final class LinkTransaction
{
    public function __construct(private StampDealMoney $stamp) {}

    /** @param  array{deal: Deal, by: string}|false|null  $hit  уже найденное `CarMatch` (false — искали, не нашли), null — искать */
    public function __invoke(Transaction $tx, bool $stamp = true, array|false|null $hit = null): ?Deal
    {
        if ($tx->matched_by === 'hand') {
            return $tx->deal;
        }
        $invoiceDeal = $tx->invoice ? ($tx->invoice->deal_id ?? $tx->invoice->credit_deal_id) : null;
        $hit = $invoiceDeal ? ['deal' => Deal::find($invoiceDeal), 'by' => 'invoice'] : ($hit ?? CarMatch::of($tx->purpose));
        $hit = $hit ?: null;
        $deal = $hit['deal'] ?? null;
        if ($tx->deal_id !== $deal?->id || $tx->matched_by !== ($hit['by'] ?? null)) {
            $was = $tx->deal_id;
            $tx->forceFill(['deal_id' => $deal?->id, 'matched_by' => $hit['by'] ?? null])->save();
            if ($stamp && $was && $was !== $deal?->id && ($old = Deal::find($was))) {
                ($this->stamp)($old);
            }
        }
        if ($stamp && $deal) {
            ($this->stamp)($deal);
        }

        return $deal;
    }
}
