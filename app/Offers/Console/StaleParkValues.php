<?php

namespace App\Offers\Console;

use App\Offers\Offer;
use App\Offers\ParkValue;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Черновики «Без продажной цены», у которых оценочная и закупочная — с заявки на приём, а машина уже на Мигторге
 * (15.11.2026, владелец: «альфа по ним нам еще не присылала оценочные — их надо оттуда убрать»). Снимает `ParkValue::drop`
 * — то же, что часы `migtorg:sync`; `--dry` только показывает. Повтор безопасен.
 */
class StaleParkValues extends Command
{
    protected $signature = 'offers:stale-park-values {--dry : только показать}';

    protected $description = 'Снять оценочную с парковки у черновиков, чья машина уже на Мигторге';

    public function handle(): int
    {
        $offers = ParkValue::stale()->orderByDesc('offers.id')->get();
        foreach ($offers as $offer) {
            /** @var Offer $offer */
            $this->line("{$offer->number} {$offer->titleWithYear()}: оценочная ".Money::plain((int) $offer->value).', закупочная '.Money::plain((int) $offer->floor_price));
        }
        if ($this->option('dry')) {
            $this->info("снять: {$offers->count()}");

            return self::SUCCESS;
        }
        $done = $offers->filter(fn (Offer $o) => ParkValue::drop($o))->count();
        $this->info("снято: {$done} из {$offers->count()}");

        return self::SUCCESS;
    }
}
