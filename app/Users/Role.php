<?php

namespace App\Users;

enum Role: string
{
    case Visitor = 'visitor';     // саморегистрация прежних времён: каталог без цен; новых не появляется
    case Buyer = 'buyer';         // покупатель менеджера: видит только открытое ему и одну цену, проявляет интерес
    case Manager = 'manager';     // внешний партнёр: цены, подтверждения, закупки, сделки, свои покупатели
    case Moderator = 'moderator'; // сотрудник: панель
    case Admin = 'admin';         // владелец: всё

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'Посетитель',
            self::Buyer => 'Покупатель',
            self::Manager => 'Менеджер',
            self::Moderator => 'Модератор',
            self::Admin => 'Администратор',
        };
    }

    /** Пускать ли в панель. Перечислено явно: новая роль не получит панель по умолчанию. */
    public function isStaff(): bool
    {
        return in_array($this, [self::Moderator, self::Admin], true);
    }

    /** Цену продажи видят все, кроме посетителя; что стоит перед стрелкой — решает PriceView. */
    public function canSeePrices(): bool
    {
        return in_array($this, [self::Buyer, self::Manager, self::Moderator, self::Admin], true);
    }

    /** Подтверждает предложение своей ценой менеджер и сотрудник. Покупатель — только интерес. */
    public function canBid(): bool
    {
        return in_array($this, [self::Manager, self::Moderator, self::Admin], true);
    }

    public function canSeePurchases(): bool
    {
        return in_array($this, [self::Manager, self::Moderator, self::Admin], true);
    }

    /** Галерея «скоро в продаже» — для тех, кто торгуется, и посетителя; покупателю только открытое ему. */
    public function canSeeGallery(): bool
    {
        return $this !== self::Buyer;
    }

    /** Чат по предложению: менеджер и посетитель — с площадкой, покупатель — со своим менеджером (нужен manager_id, см. User::canChat). */
    public function canChat(): bool
    {
        return in_array($this, [self::Visitor, self::Manager, self::Buyer], true);
    }

    /** Поделиться PDF — всем, кроме покупателя: его цена — не для пересылки дальше. */
    public function canShare(): bool
    {
        return $this !== self::Buyer;
    }

    /** Проявляет интерес тот, кто не подтверждает ценой: покупатель и посетитель. */
    public function canInterest(): bool
    {
        return in_array($this, [self::Visitor, self::Buyer], true);
    }
}
