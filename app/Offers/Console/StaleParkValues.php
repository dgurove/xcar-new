<?php

namespace App\Offers\Console;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\ParkValue;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Черновики, у которых оценочная и закупочная — с заявки на приём, а машина уже на Мигторге (15.11.2026, владелец: «альфа
 * по ним нам еще не присылала оценочные — их надо оттуда убрать»; про оценённые и опубликованные — «пусть останется цена
 * продажи, но пока нет оценочной и закупочной, они черновики без закупочной»; сделки — не трогать). Снимает
 * `ParkValue::drop` — то же, что часы `migtorg:sync`; `--open` — сначала снимает с продажи опубликованные без подтверждений;
 * `--dry` только показывает. Повтор безопасен.
 */
class StaleParkValues extends Command
{
    protected $signature = 'offers:stale-park-values {--dry : только показать} {--open : и опубликованные без подтверждений — снять с продажи}';

    protected $description = 'Снять оценочную с парковки у черновиков, чья машина уже на Мигторге';

    public function handle(): int
    {
        $open = (bool) $this->option('open');
        $offers = ParkValue::stale($open ? OfferState::Open : OfferState::Draft)->orderByDesc('offers.id')->get();
        foreach ($offers as $offer) {
            /** @var Offer $offer */
            $this->line("{$offer->number} {$offer->titleWithYear()}: оценочная ".Money::plain((int) $offer->value).', закупочная '.Money::plain((int) $offer->floor_price).($offer->asking_price ? ', цена продажи '.Money::plain((int) $offer->asking_price) : ''));
        }
        if ($this->option('dry')) {
            $this->info("снять: {$offers->count()}");

            return self::SUCCESS;
        }
        $done = $offers->filter(fn (Offer $o) => ParkValue::drop($open ? app(ChangeOfferState::class)($o, OfferState::Draft, null) : $o))->count();
        $this->info("снято: {$done} из {$offers->count()}");

        return self::SUCCESS;
    }
}
