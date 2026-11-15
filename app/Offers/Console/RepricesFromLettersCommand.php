<?php

namespace App\Offers\Console;

use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Extraction\Templates\Template;
use App\Offers\Actions\DetectReprice;
use App\Offers\Actions\FillFromLetter;
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
            ->whereHas('thread', fn ($t) => $t->whereNotNull('offer_id'))->with('thread')->orderByDesc('date_at')->get();
        $found = 0;
        $seen = [];
        // По машине — только последнее письмо с ценой (15.11.2026, «Цена за Лот» Т-Страхования): цепочка из старых писем
        // завела бы переоценки, которые тут же «заменены новой».
        foreach ($letters as $letter) {
            $offer = isset($seen[$letter->thread->offer_id]) ? null : Offer::find($letter->thread->offer_id);
            if (! $offer || ! $letter->isFromVendor() || ! FillFromLetter::about($offer, $letter)) {
                continue;
            }
            $text = $letter->ownText();
            // Последнее письмо с ценой решает, даже если цена в нём совпала с закупочной: старое письмо с другой — уже прошлое.
            if (Template::newPrice($text) ?? Template::lotPrice($text) ?? Template::topBid($text)) {
                $seen[$offer->id] = true;
            }
            if (! ($price = DetectReprice::pricesIn($offer, $letter)['floor_price'] ?? null)) {
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
