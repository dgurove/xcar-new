<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Сделка дошла до оплаты, а счёта нет — ход наш (05.10.2026: без этого обе стороны ждали друг друга пять дней).
 * Сотрудникам в «Сделки»: ссылка ведёт прямо в «Выставить счёт».
 */
final class InvoiceNeededNotice extends Notice
{
    /** @param  ?string  $gap  `share` — гаражной не вписана наша доля (счёт со ссылкой встанет сам), иначе счёт руками */
    public function __construct(private Deal $deal, private ?string $gap = null) {}

    public function title(): string
    {
        return ($this->gap === 'share' ? 'Впишите нашу долю: ' : 'Выставите счёт: ').$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->deal->buyer ? 'Менеджер '.$this->deal->buyer->name : null;
    }

    public function href(): string
    {
        return '/work/deals/'.$this->deal->id.'#money';
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function subject(): string
    {
        return '/work/deals/'.$this->deal->id;
    }
}
