<?php

namespace App\Billing\Bank\Console;

use App\Billing\Bank\Actions\MatchByVin;
use App\Billing\Bank\Transaction;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Прошлые нераспознанные поступления — по VIN в сделку (12.11.2026, `MatchByVin`): с 01.10 лежали все 115, у 37 VIN совпадал со
 * сделкой. `--dry` — только список «поступление → сделка», без записи.
 */
final class MatchByVinCommand extends Command
{
    protected $signature = 'bank:match-vin {--since=2026-10-01 : с какого дня} {--dry : только показать}';

    protected $description = 'Нераспознанные поступления с VIN — в сделку зачётом';

    public function handle(MatchByVin $match): int
    {
        $txs = Transaction::where('direction', 'in')->where('state', Transaction::UNMATCHED)
            ->where('booked_at', '>=', $this->option('since'))->orderBy('booked_at')->orderBy('id')->get();
        $n = 0;
        foreach ($txs as $tx) {
            $deal = MatchByVin::dealFor($tx);
            if (! $deal) {
                continue;
            }
            $line = $tx->booked_at->format('d.m').'  '.Money::rub((float) $tx->amount).'  → '.$deal->offer->number.' '.$deal->offer->titleWithYear().', '.$deal->buyer?->name;
            if ($this->option('dry')) {
                $this->line($line);
                $n++;

                continue;
            }
            if ($match($tx)) {
                $this->line($line);
                $n++;
            } else {
                $this->warn('не привязано: '.$line);
            }
        }
        $this->info(($this->option('dry') ? 'Привяжется: ' : 'Привязано: ').$n.' из '.$txs->count().' нераспознанных');

        return self::SUCCESS;
    }
}
