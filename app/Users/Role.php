<?php

namespace App\Users;

enum Role: string
{
    case Visitor = 'visitor';     // саморегистрация прежних времён: каталог без цен; новых не появляется
    case Buyer = 'buyer';         // покупатель менеджера: видит только открытое ему и одну цену, проявляет интерес
    case Manager = 'manager';     // внешний партнёр: цены, подтверждения, закупки, сделки, свои покупатели
    case Moderator = 'moderator'; // сотрудник CRM: заводит черновики предложений, сверх них — галки CrmArea; сайта нет
    case Admin = 'admin';         // владелец: всё
    case Parking = 'parking';     // управляющий парковкой: только park.xcar, вся парковка; «только приёмка» — помощник; без настроек и продаж
    case ParkAdmin = 'park_admin'; // администратор парковки: вся парковка с настройками и людьми; отдельно от админа CRM (08.10.2026)
    case Reviewer = 'reviewer';   // проверяющий (модерация ЮKassa): открытые предложения с ценой продажи, действий нет
    case SuperBuyer = 'super_buyer'; // супер-покупатель (10.10.2026): без менеджера, все предложения своей волны, только цена продажи, подтверждает как менеджер
    case Logist = 'logist';       // логист (10.10.2026, Татьяна): logistics.xcar — все перевозки, межрегион назначает она, стоимость и сроки
    case Driver = 'driver';       // водитель нашего эвакуатора: logistics.xcar — только свои рейсы, «Забрал» и «Сдал»

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'Посетитель',
            self::Buyer => 'Покупатель',
            self::Manager => 'Менеджер',
            self::Moderator => 'Модератор',
            self::Admin => 'Администратор',
            self::Parking => 'Парковка',
            self::ParkAdmin => 'Администратор парковки',
            self::Reviewer => 'Проверяющий',
            self::SuperBuyer => 'Супер-покупатель',
            self::Logist => 'Логист',
            self::Driver => 'Водитель',
        };
    }

    /**
     * Как роль зовут самому человеку. «Супер-покупатель» — имя для админов (владелец 10.10.2026: «пусть он думает,
     * что он просто покупатель»), ему самому он — «Покупатель».
     */
    public function selfLabel(): string
    {
        return $this === self::SuperBuyer ? self::Buyer->label() : $this->label();
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
        return in_array($this, [self::Buyer, self::Manager, self::Moderator, self::Admin, self::Reviewer, self::SuperBuyer], true);
    }

    /**
     * Подтверждает предложение своей ценой менеджер и супер-покупатель (08.10.2026: админ — «+ За менеджера» в CRM).
     * Покупатель — интерес.
     */
    public function canBid(): bool
    {
        return in_array($this, [self::Manager, self::SuperBuyer], true);
    }

    public function canSeePurchases(): bool
    {
        return in_array($this, [self::Manager, self::Admin], true);
    }

    /** Чат по предложению: менеджер, супер-покупатель и посетитель — с площадкой, покупатель — со своим менеджером (нужен manager_id, см. User::canChat). */
    public function canChat(): bool
    {
        return in_array($this, [self::Visitor, self::Manager, self::Buyer, self::SuperBuyer], true);
    }

    /** Поделиться PDF — всем, кроме покупателей (их цена — не для пересылки дальше) и модератора (у него черновики). */
    public function canShare(): bool
    {
        return ! in_array($this, [self::Buyer, self::SuperBuyer, self::Parking, self::ParkAdmin, self::Reviewer, self::Moderator, self::Logist, self::Driver], true);
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
