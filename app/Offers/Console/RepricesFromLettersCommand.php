<?php

namespace App\Offers\Console;

use App\Mail\Direction;
use App\Mail\Message;
use App\Offers\Actions\DetectReprice;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Разово после выкладки переоценки (28.10.2026): письма поставщика с новой ценой, пришедшие раньше (653253 — 433 200
 * вместо 594 762 так и не внесли), заводят ждущие переоценки. Деньги не меняются — только плашка на решение. Без
 * `--apply` — что найдётся, списком.
 */
class RepricesFromLettersCommand extends Command
{
    protected $signature = 'offers:reprices-from-letters {--days=30} {--apply}';

    protected $description = 'Письма с новой ценой по заведённым машинам — переоценки на решение';

    public function handle(DetectReprice $detect): int
    {
        $letters = Message::query()->where('direction', Direction::In)->where('date_at', '>', now()->subDays((int) $this->option('days')))
            ->whereHas('thread', fn ($t) => $t->whereNotNull('offer_id'))->with('thread')->orderBy('date_at')->get();
        $found = 0;
        foreach ($letters as $letter) {
            $offer = Offer::find($letter->thread->offer_id);
            if (! $offer || ! ($price = DetectReprice::priceIn($offer, $letter))) {
                continue;
            }
            $found++;
            $this->line("{$offer->number} {$offer->claim_ref} {$offer->titleWithYear()}: ".Money::plain((int) $offer->floor_price).' → '.Money::plain($price)
                ." (письмо {$letter->id}, {$letter->date_at?->format('d.m H:i')})");
            if ($this->option('apply')) {
                $detect($offer, $letter);
            }
        }
        $this->info(($this->option('apply') ? 'Заведено' : 'Заведётся').": {$found}");

        return self::SUCCESS;
    }
}
