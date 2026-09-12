<?php

namespace App\Users;

enum Role: string
{
    case Visitor = 'visitor';     // зарегистрировался сам: каталог без цен, интерес
    case Manager = 'manager';     // внешний партнёр: цены, подтверждения, закупки, сделки
    case Moderator = 'moderator'; // сотрудник: панель
    case Admin = 'admin';         // владелец: всё

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'Посетитель',
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

    public function canSeePrices(): bool
    {
        return $this !== self::Visitor;
    }

    public function canBid(): bool
    {
        return $this === self::Manager;
    }

    public function canSeePurchases(): bool
    {
        return $this !== self::Visitor;
    }
}
