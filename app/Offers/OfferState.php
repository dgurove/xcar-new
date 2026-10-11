<?php

namespace App\Offers;

use App\Cars\HasLabels;
use App\Users\User;

enum OfferState: string
{
    use HasLabels;

    case Draft = 'draft';
    case Gallery = 'gallery';       // прежняя галерея-состояние; с 08.10.2026 галерея — окно показа черновика (`Offer::isGallery`)
    case Open = 'open';             // в каталоге; подтверждения принимаются, пока не прошёл bids_close_at
    case Garage = 'garage';         // отдана менеджеру в гараж: из продажи ушла, на витрину не вернётся
    case Sold = 'sold';             // идёт сделка
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    /**
     * Состояние в CRM для того, кто смотрит: модератору подтверждения не видны (принимает их только админ), открытое
     * для него — «В продаже», а не «Приём подтверждений».
     */
    public function labelFor(?User $user): string
    {
        return $this === self::Open && ! $user?->canManageCrm() ? 'В продаже' : $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Gallery => 'В галерее',
            self::Open => 'Приём подтверждений',
            self::Garage => 'В гараже',
            self::Sold => 'Идёт сделка',
            self::Delivered => 'Выдан',
            self::Cancelled => 'Снят',
            self::Archived => 'В архиве',
        };
    }

    /** Цвет чипа: open / urgent / closed / danger / plain. */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Sold => 'urgent',
            self::Cancelled => 'danger',
            self::Draft, self::Gallery, self::Garage => 'plain',
            default => 'closed',
        };
    }

    public function isPublic(): bool
    {
        return $this === self::Open;
    }

    public function acceptsBids(): bool
    {
        return $this === self::Open;
    }

    /** Интерес на открытом; в галерее — по окну показа черновика (`Offer::acceptsInterest`). */
    public function acceptsInterest(): bool
    {
        return $this === self::Open;
    }

    /**
     * Что сотрудник может сделать кнопкой из этого состояния — только уместное: «Снять с продажи» есть лишь у того,
     * что в продаже; у машины в сделке — только отмена сделки (в продажу или в черновики), остальное ведут маршрут и
     * подтверждения. Выдача и гараж — своими действиями, не отсюда.
     *
     * @return array<string, string> значение состояния => подпись кнопки
     */
    public function actions(): array
    {
        return match ($this) {
            // «В галерею» — не переход: черновик остаётся черновиком, у него открывается окно показа (`ShowInGallery`).
            self::Draft => [self::Open->value => 'Опубликовать', self::Gallery->value => 'В галерею', self::Archived->value => 'В архив'],
            self::Gallery => [self::Open->value => 'Опубликовать', self::Draft->value => 'Снять с продажи', self::Archived->value => 'В архив'],
            self::Open => [self::Draft->value => 'Снять с продажи', self::Archived->value => 'В архив'],
            self::Delivered, self::Cancelled => [self::Archived->value => 'В архив'],
            self::Archived => [self::Draft->value => 'Вернуть в черновики'],
            // Отменить сделку руками (10.10.2026, владелец: «отдал в сделку — нет кнопки отменить»): назад в продажу к
            // остальным подтверждениям или в черновики; гасит сделку `ChangeOfferState::settleDeal` → `CancelDeal`.
            // В продажу — шторкой с ценами (`x-offer.resale-sheet`, `ReturnToSale`), не этой кнопкой.
            self::Sold => [self::Draft->value => 'Отменить сделку, в черновики'],
            self::Garage => [],
        };
    }

    public function allows(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Open, self::Sold, self::Garage, self::Cancelled, self::Archived],
            self::Gallery => [self::Draft, self::Open, self::Sold, self::Garage, self::Cancelled, self::Archived],
            self::Open => [self::Draft, self::Sold, self::Garage, self::Cancelled, self::Archived],
            // Из гаража на витрину — только «Вернуть в продажу» с новыми ценами (`ReturnToSale`, 11.10.2026), «отдали по
            // ошибке» — в черновик.
            self::Garage => [self::Draft, self::Open, self::Archived],
            // В гараж из сделки — только гаражной, концом её маршрута (`ChangeOfferState`); руками «Отдать в гараж» у Sold нет.
            self::Sold => [self::Delivered, self::Garage, self::Cancelled, self::Open, self::Draft],
            self::Archived => [self::Draft],
            self::Delivered, self::Cancelled => [self::Archived],
        }, true);
    }
}
