<?php

namespace App\Billing\Bank\Console;

use App\Billing\Bank\Actions\BuyerFromStatement;
use App\Billing\Bank\Transaction;
use App\Billing\Robot;
use App\Offers\Deal;
use App\Offers\DealState;
use Illuminate\Console\Command;

/**
 * Поступления, уже привязанные по VIN, — плательщика покупателем сделки (12.11.2026, `BuyerFromStatement`). `--dry` — только
 * список «сделка → плательщик», без записи.
 */
final class BuyersFromStatementCommand extends Command
{
    protected $signature = 'bank:buyers-from-statement {--dry : только показать}';

    protected $description = 'Плательщик из выписки — покупателем сделки, где покупателя нет';

    public function handle(BuyerFromStatement $set): int
    {
        $n = 0;
        foreach (Transaction::where('note', 'like', 'по VIN%')->orderBy('booked_at')->orderBy('id')->get() as $tx) {
            $number = (int) preg_replace('/\D/', '', (string) $tx->note);
            $deal = Deal::whereHas('offer', fn ($o) => $o->where('number', $number))->whereIn('state', [DealState::Active, DealState::Parallel])
                ->with(['offer', 'buyer', 'contract'])->first();
            if (! $deal || $deal->contract?->buyer_user_id || ! $deal->collectsBuyer() || ! ($p = BuyerFromStatement::payer($tx, $deal))) {
                continue;
            }
            $line = $number.' '.$deal->offer->titleWithYear().', '.$deal->buyer?->name.'  → '.$p['name'].($p['inn'] ? ', ИНН '.$p['inn'] : '').' ('.$p['kind']->value.')';
            if ($this->option('dry')) {
                $this->line($line);
                $n++;

                continue;
            }
            $set($tx, $deal, Robot::user()) ? $this->line($line) : $this->warn('не встал: '.$line);
            $n++;
        }
        $this->info(($this->option('dry') ? 'Встанет покупателей: ' : 'Покупателей: ').$n);

        return self::SUCCESS;
    }
}
