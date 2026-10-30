<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Plural;

/**
 * Новые предложения пачкой после 5 минут тишины (`NotifyNewOffers`, 16.10.2026): «Опубликовано 3 предложения», ниже
 * марки, пока влезают, — «Haval, Tenet, Kia»; одно — «Новое предложение: Tenet T4, 2023» и ведёт на него, пачка — в
 * каталог на пилюлю «Новые». В ленту и колокольчик не идёт (владелец: «не захламлять раздел уведомлений»): пуш, кроме
 * тихих часов, карточка в приложении со звуком, которая уходит сама, и с 21.10.2026 основной бот Telegram (владелец:
 * «менеджеры не видят, когда приходят новые машины»; пуши были у 7 менеджеров из 25, Telegram — у 17). Подписчику бота
 * предложений основной бот не пишет: тот уже пишет ему «Появились новые предложения». Пуши заменяют друг друга: в
 * шторке телефона висит один.
 */
final class NewOffersNotice extends Notice
{
    use OffersPack;

    /** Строка марок — до стольких знаков, дальше «и ещё 2 марки». */
    private const ROOM = 100;

    /** @param  list<string>  $brands  марки пачки без повторов, частые первыми */
    public function __construct(private int $count, private array $brands, private ?Offer $offer = null) {}

    public function label(): string
    {
        return 'Новые предложения';
    }

    public function title(): string
    {
        if ($this->offer) {
            return 'Новое предложение: '.$this->offer->titleWithYear();
        }

        return 'Опубликовано '.$this->count.' '.Plural::of($this->count, ['предложение', 'предложения', 'предложений']);
    }

    public function text(): ?string
    {
        if ($this->offer) {
            return $this->offer->bids_close_at ? 'Приём до '.$this->offer->bids_close_at->translatedFormat('j M, H:i') : null;
        }

        return self::brandsLine($this->brands);
    }

    /** «Haval, Tenet, Kia» — пока влезает, остальное числом: «Haval, Tenet и ещё 3 марки». */
    public static function brandsLine(array $brands): ?string
    {
        $shown = [];
        foreach ($brands as $i => $brand) {
            if ($shown && mb_strlen(implode(', ', [...$shown, $brand])) > self::ROOM) {
                $rest = count($brands) - $i;

                return implode(', ', $shown).' и ещё '.$rest.' '.Plural::of($rest, ['марка', 'марки', 'марок']);
            }
            $shown[] = $brand;
        }

        return $shown ? implode(', ', $shown) : null;
    }

    public function href(): string
    {
        return $this->offer ? "/offers/{$this->offer->number}" : '/offers?view=fresh';
    }

    /** Одна тема на все: новая карточка и новый пуш заменяют прежние. */
    public function subject(): string
    {
        return '/offers';
    }

    public function offerNumber(): ?int
    {
        return $this->offer?->number;
    }

    /** Одно предложение ушло от человека (сняли, сдвинули волну) — сообщение удаляется (`RetractOfferNotices`). */
    public function telegramSubject(): ?string
    {
        return $this->offer ? self::offerSubject($this->offer->id) : null;
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
