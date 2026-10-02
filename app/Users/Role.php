<?php

namespace App\Users;

enum Role: string
{
    case Visitor = 'visitor';     // саморегистрация прежних времён: каталог без цен; новых не появляется
    case Buyer = 'buyer';         // покупатель менеджера: видит только открытое ему и одну цену, проявляет интерес
    case Manager = 'manager';     // внешний партнёр: цены, подтверждения, закупки, сделки, свои покупатели
    case Moderator = 'moderator'; // сотрудник CRM: заводит черновики предложений, сверх них — галки CrmArea; сайта нет
    case Admin = 'admin';         // владелец: всё
    case Parking = 'parking';     // управляющий парковкой: только park.xcar, разделы — по галкам (Park\Area), без настроек
    case Reviewer = 'reviewer';   // проверяющий (модерация ЮKassa): открытые предложения с ценой продажи, действий нет

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'Посетитель',
            self::Buyer => 'Покупатель',
            self::Manager => 'Менеджер',
            self::Moderator => 'Модератор',
            self::Admin => 'Администратор',
            self::Parking => 'Парковка',
            self::Reviewer => 'Проверяющий',
        };
    }

    /** Пускать ли в панель. Перечислено явно: новая роль не получит панель по умолчанию. */
    public function isStaff(): bool
    {
        return in_array($this, [self::Moderator, self::Admin], true);
    }

    /**
     * Вся CRM: цена продажи и публикация, круг показа, подтверждения, сделки, деньги, чаты, закупки, люди, настройки.
     * Модератору — нет: у него черновики (и почта галкой `CrmArea`), см. явный список в `routes/crm.php`.
     */
    public function canManageCrm(): bool
    {
        return in_array($this, [self::Admin], true);
    }

    /** Цену продажи видят все, кроме посетителя; что стоит перед стрелкой — решает PriceView. */
    public function canSeePrices(): bool
    {
        return in_array($this, [self::Buyer, self::Manager, self::Moderator, self::Admin, self::Reviewer], true);
    }

    /** Подтверждает предложение своей ценой менеджер и админ. Покупатель — только интерес. */
    public function canBid(): bool
    {
        return in_array($this, [self::Manager, self::Admin], true);
    }

    public function canSeePurchases(): bool
    {
        return in_array($this, [self::Manager, self::Admin], true);
    }

    /** Галерея «скоро в продаже» — для тех, кто торгуется, и посетителя; покупателю только открытое ему. */
    public function canSeeGallery(): bool
    {
        return ! in_array($this, [self::Buyer, self::Parking, self::Reviewer, self::Moderator], true);
    }

    /** Чат по предложению: менеджер и посетитель — с площадкой, покупатель — со своим менеджером (нужен manager_id, см. User::canChat). */
    public function canChat(): bool
    {
        return in_array($this, [self::Visitor, self::Manager, self::Buyer], true);
    }

    /** Поделиться PDF — всем, кроме покупателя (его цена — не для пересылки дальше) и модератора (у него черновики). */
    public function canShare(): bool
    {
        return ! in_array($this, [self::Buyer, self::Parking, self::Reviewer, self::Moderator], true);
    }

    /** Гараж — машины на ремонте у менеджеров: сами менеджеры и админ, что вносит итог и счёт. */
    public function canGarage(): bool
    {
        return in_array($this, [self::Manager, self::Admin], true);
    }

    /** Проявляет интерес тот, кто не подтверждает ценой: покупатель и посетитель. */
    public function canInterest(): bool
    {
        return in_array($this, [self::Visitor, self::Buyer], true);
    }
}
