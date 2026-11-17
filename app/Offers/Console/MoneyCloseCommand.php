<?php

namespace App\Offers\Console;

use App\Offers\Actions\MoneyIn;
use App\Offers\Actions\SetDealDate;
use App\Offers\Actions\StampDealMoney;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Support\Money;
use App\Workflow\Track;
use Illuminate\Console\Command;

/**
 * Прошлое правила «деньги пришли — шаг закрыт» (17.11.2026, `MoneyIn`): идущие сделки на шаге оплаты, у которых деньги без
 * счёта уже пришли (или «Продано» / оплата «страховой напрямую» вписаны руками), и сделки на шаге нашей оплаты поставщику,
 * которому уже заплатили по выписке. `--dry` — только список, владельцу до выкладки; без него — проходит шаги.
 */
class MoneyCloseCommand extends Command
{
    protected $signature = 'deals:money-close {--dry}';

    protected $description = 'Деньги пришли — шаг оплаты закрыт: прошлые сделки';

    public function handle(MoneyIn $money, SetDealDate $set): int
    {
        $dry = (bool) $this->option('dry');
        $count = 0;
        foreach (Deal::active()->with(['offer.vendor.party', 'offer.positions.stage.exits', 'buyer'])->orderBy('id')->get() as $deal) {
            $stage = $deal->offer?->position(Track::Sale)?->stage;
            $line = fn (string $why) => implode(' | ', array_filter([$deal->offer->number, $deal->offer->claim_ref, $deal->offer->vendor?->name, $deal->buyer?->name, $stage?->name, $why]));
            if (MoneyIn::closes($deal) || MoneyIn::directDated($deal)) {
                $got = MoneyIn::received($deal);
                $why = MoneyIn::directDated($deal) ? 'оплата поставщику вписана руками'
                    : ($got > 0 ? 'пришло '.Money::plain($got).' из '.Money::plain((float) $deal->base()) : '«Продано» вписано руками');
                $this->line($line($why));
                $dry || $money($deal);
                $count++;
            } elseif ($stage?->paysOut() && $deal->supplier_paid_at && DealDates::paidColumn($deal) === 'supplier_paid_at') {
                $this->line($line('поставщику заплачено '.$deal->supplier_paid_at->format('d.m')));
                $dry || $set->passPaid($deal, $deal->offer, $deal->supplier_paid_at, null, StampDealMoney::firstOut($deal)?->doc_number);
                $count++;
            }
        }
        $this->info(($dry ? 'Пройдут шаг: ' : 'Прошли шаг: ').$count);

        return self::SUCCESS;
    }
}
