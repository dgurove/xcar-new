<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Plural;
use App\Users\User;

/** Покупателю: менеджер открыл ему предложения — одно сообщение на пачку. */
final class OffersShownNotice extends Notice
{
    /** @param list<int> $offerIds */
    public function __construct(private User $manager, private array $offerIds) {}

    public function title(): string
    {
        $n = count($this->offerIds);
        if ($n === 1) {
            $offer = Offer::find($this->offerIds[0]);

            return $this->manager->shortName().' открыл вам '.($offer?->titleWithYear() ?? 'автомобиль');
        }

        return $this->manager->shortName().' открыл вам '.$n.' '.Plural::of($n, ['автомобиль', 'автомобиля', 'автомобилей']);
    }

    public function text(): ?string
    {
        return count($this->offerIds) === 1 ? 'Посмотрите и отметьте интерес, если подходит' : 'Всё в вашей ленте';
    }

    public function href(): string
    {
        return count($this->offerIds) === 1 ? '/offers/'.(Offer::find($this->offerIds[0])?->number ?? '') : '/offers';
    }

    public function offerNumber(): ?int
    {
        return count($this->offerIds) === 1 ? Offer::find($this->offerIds[0])?->number : null;
    }

    public function category(): string
    {
        return 'offers';
    }

    /** В Telegram покупателю (12.10.2026): одна машина — с ценой, пачка — числом; кнопка в ленту или на машину. */
    public function toTelegram(): ?array
    {
        $offer = count($this->offerIds) === 1 ? Offer::find($this->offerIds[0]) : null;
        // Покупателю — одна цена, продажная (`PriceView`); VIN ему по умолчанию скрыт — его тут нет.
        $price = $offer?->asking_price ? \App\Support\Money::rub($offer->asking_price) : null;

        return ['title' => $this->title(), 'lines' => array_values(array_filter([$price, $this->text()])), 'button' => $offer ? 'Посмотреть' : 'Открыть ленту'];
    }

    /** Одна машина — сообщение о ней: пропала у покупателя — `RetractOfferNotices` уберёт; пачку не трогаем. */
    public function telegramSubject(): ?string
    {
        return count($this->offerIds) === 1 ? self::offerSubject($this->offerIds[0]) : null;
    }

    protected function visibleOffer(): ?Offer
    {
        return count($this->offerIds) === 1 ? Offer::find($this->offerIds[0]) : null;
    }
}
