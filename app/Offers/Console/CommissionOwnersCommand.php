<?php

namespace App\Offers\Console;

use App\Billing\Seller;
use App\Offers\Actions\FillCommissionOwner;
use App\Offers\Deal;
use App\Offers\DealState;
use Illuminate\Console\Command;

/**
 * Разово (14.11.2026, новый ДКП ПРАЙМ): идущим сделкам ПРАЙМ без собственника — комитент из договора комиссии в письмах
 * и документах (`FillCommissionOwner`). Без `--apply` — что встанет, списком; в конце — у кого не нашлось.
 */
class CommissionOwnersCommand extends Command
{
    protected $signature = 'deals:commission-owners {--apply}';

    protected $description = 'Собственник для ДКП ПРАЙМ из договора комиссии у идущих сделок';

    public function handle(FillCommissionOwner $fill): int
    {
        $apply = (bool) $this->option('apply');
        $none = [];
        $deals = Deal::with(['offer.vendor', 'contract'])->where('state', DealState::Active)->get()
            ->filter(fn (Deal $d) => $d->hasContract() && ! $d->isDkp() && $d->seller() === Seller::Prime && ! ($d->contract?->seller_party_id && $d->contract->supplier_price !== null));
        foreach ($deals as $deal) {
            $found = $fill($deal, $apply);
            $title = "сделка {$deal->id} {$deal->offer->claim_ref} ({$deal->offer->vendor?->name})";
            if (! $found) {
                $none[] = $title;

                continue;
            }
            $p = $found['party'];
            $this->line($title.': № '.($found['number'] ?? '—').', '.$p['name'].', '.($p['passport'] ?? 'ИНН '.($p['inn'] ?? '—'))
                .', поставщику '.($found['payment']['price'] ?? '—'));
        }
        $this->info(($apply ? 'Вписано' : 'Найдётся').': '.($deals->count() - count($none)).' из '.$deals->count());
        foreach ($none as $t) {
            $this->warn('нет: '.$t);
        }

        return self::SUCCESS;
    }
}
