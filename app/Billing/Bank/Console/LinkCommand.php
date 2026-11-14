<?php

namespace App\Billing\Bank\Console;

use App\Billing\Bank\Actions\LinkTransaction;
use App\Billing\Bank\CarMatch;
use App\Billing\Bank\Transaction;
use App\Offers\Actions\StampDealMoney;
use App\Offers\Deal;
use App\Offers\DealDates;
use Illuminate\Console\Command;

/**
 * Пересопоставить выписку с машинами и пересчитать «Продано» / «Оплачено» всех сделок с деньгами (14.11.2026). `--dry` —
 * только посчитать: сколько строк узнано и как, сколько дат встанет.
 */
final class LinkCommand extends Command
{
    protected $signature = 'bank:link {--dry : только показать}';

    protected $description = 'Привязывает выписку к машинам и пересчитывает даты сделок';

    public function handle(LinkTransaction $link, StampDealMoney $stamp): int
    {
        $dry = (bool) $this->option('dry');
        $by = ['in' => [], 'out' => []];
        $touched = [];
        foreach (Transaction::orderBy('id')->lazy() as $tx) {
            // Прежняя сделка строки тоже пересчитывается: её дата могла держаться только на этой строке.
            $tx->deal_id && $touched[] = $tx->deal_id;
            $hit = $dry ? CarMatch::of($tx->purpose) : null;
            $deal = $dry ? ($hit['deal'] ?? null) : $link($tx, stamp: false);
            $key = $dry ? ($hit['by'] ?? 'нет') : ($tx->fresh()->matched_by ?? 'нет');
            $by[$tx->direction][$key] = ($by[$tx->direction][$key] ?? 0) + 1;
        }
        foreach ($by as $dir => $counts) {
            $this->line(($dir === 'in' ? 'Входящие' : 'Исходящие').': '.collect($counts)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
        }
        if ($dry) {
            return self::SUCCESS;
        }
        $before = ['sold' => 0, 'paid' => 0];
        $after = ['sold' => 0, 'paid' => 0];
        foreach (StampDealMoney::dealsWithMoney()->merge(Deal::whereKey(array_unique($touched))->get())->unique('id') as $deal) {
            DealDates::sold($deal) && $before['sold']++;
            DealDates::paid($deal) && $before['paid']++;
            $deal = $stamp($deal);
            DealDates::sold($deal) && $after['sold']++;
            DealDates::paid($deal) && $after['paid']++;
        }
        $this->info("Продано: {$before['sold']} → {$after['sold']}, оплачено: {$before['paid']} → {$after['paid']}");

        return self::SUCCESS;
    }
}
