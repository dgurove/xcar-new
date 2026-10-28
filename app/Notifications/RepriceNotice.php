<?php

namespace App\Notifications;

use App\Offers\Reprice;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/**
 * Админам: поставщик прислал новую цену по заведённой машине (28.10.2026, `DetectReprice`) — плашка «Переоценка» ждёт
 * решения в окошке и редакторе. Важное: по письму 653253 цена 433 200 вместо 594 762 пролежала незамеченной.
 */
final class RepriceNotice extends Notice
{
    public function __construct(private Reprice $reprice) {}

    public function title(): string
    {
        return 'Переоценка: '.$this->reprice->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $r = $this->reprice;

        return Money::plain((int) $r->from_price).' → '.Money::rub($r->to_price).', '.$r->sourceLabel();
    }

    public function href(): string
    {
        return Surface::Crm->url('/offers/'.$this->reprice->offer->number);
    }

    public function offerNumber(): ?int
    {
        return $this->reprice->offer->number;
    }

    public function category(): string
    {
        return 'money';
    }

    public function important(): bool
    {
        return true;
    }

    public function subject(): string
    {
        return '/offers/'.$this->reprice->offer->number.'/reprice';
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->reprice->offer, $this->text()), 'button' => 'Разобрать'];
    }
}
