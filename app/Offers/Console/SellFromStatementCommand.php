<?php

namespace App\Offers\Console;

use App\Offers\Actions\SellFromStatement;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Прошлые гаражные «Деньги пришли: записать продажу» — по одной (18.11.2026, владелец: «не массовым скриптом, а по одному
 * внимательно», «в истории помечать, что это догадки»): номер предложения → что видно по выписке и что запишем; `--apply` —
 * записать догадкой (`SellFromStatement`).
 */
class SellFromStatementCommand extends Command
{
    protected $signature = 'garage:sell-from-statement {number : номер предложения} {--apply}';

    protected $description = 'Записать гаражную продажу одной машины догадкой по выписке';

    public function handle(SellFromStatement $sell): int
    {
        $offer = Offer::where('number', $this->argument('number'))->first();
        $deal = $offer?->garageCar?->deal ?? $offer?->deal;
        if (! $deal) {
            $this->error('Нет гаражной сделки');

            return self::FAILURE;
        }
        $this->line("№ {$offer->number} {$offer->titleWithYear()}, менеджер ".($deal->buyer?->name ?? 'нет').', закупочная '.Money::rub((int) $deal->cost));
        foreach (SellFromStatement::arrivals($deal) as $tx) {
            $this->line('  '.$tx->booked_at->format('d.m').' '.Money::rub($tx->amount).' — '.$tx->counterparty.' — '.mb_substr(preg_replace('/\s+/u', ' ', (string) $tx->purpose), 0, 120));
        }
        $g = SellFromStatement::guess($deal);
        if (! $g['ok']) {
            $this->warn('Не гадаем: '.$g['why']);

            return self::SUCCESS;
        }
        $fee = $deal->namesBuyer() ? $deal->suggestedCommission($g['price']) : null;
        $this->line('Запишем: продана за '.Money::rub($g['price']).', покупатель '.$g['payer'].($fee !== null ? ', вознаграждение менеджеру '.Money::rub($fee) : ''));
        if ($this->option('apply')) {
            $car = $sell($deal);
            $car ? $this->info('Записано') : $this->error('Не записалось — см. лог');
        }

        return self::SUCCESS;
    }
}
