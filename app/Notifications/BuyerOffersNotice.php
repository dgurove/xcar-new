<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Money;
use App\Support\Plural;

/**
 * Покупателю, что видит всё открытое менеджеру (12.10.2026, `buyer_sees = all`): волна его менеджера наступила — одно
 * уведомление на всё, что открылось за раз. Одна машина — с ценой продажи (ему другой нет), пачка — числом.
 */
final class BuyerOffersNotice extends Notice
{
    /** @param list<int> $offerIds */
    public function __construct(private array $offerIds) {}

    private function single(): ?Offer
    {
        return count($this->offerIds) === 1 ? Offer::find($this->offerIds[0]) : null;
    }

    public function title(): string
    {
        $n = count($this->offerIds);
        if ($offer = $this->single()) {
            return 'Новое предложение: '.$offer->titleWithYear();
        }

        return 'Новые предложения: '.$n.' '.Plural::of($n, ['автомобиль', 'автомобиля', 'автомобилей']);
    }

    public function text(): ?string
    {
        $offer = $this->single();

        return $offer?->asking_price ? Money::rub($offer->asking_price) : null;
    }

    public function href(): string
    {
        return ($offer = $this->single()) ? "/offers/{$offer->number}" : '/offers';
    }

    public function offerNumber(): ?int
    {
        return $this->single()?->number;
    }

    public function category(): string
    {
        return 'offers';
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => array_values(array_filter([$this->text()])), 'button' => $this->single() ? 'Посмотреть' : 'Открыть ленту'];
    }
}
