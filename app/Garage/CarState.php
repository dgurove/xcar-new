<?php

namespace App\Garage;

use App\Cars\HasLabels;

/**
 * Этап машины в гараже (06.10.2026, три дорожки). Гараж — только подготовка: ждёт машину (её везёт вывоз) →
 * подготовка → готова. Дальше — продажа, она дорожкой «Продажа» после маршрута со страховой: в продаже → продана →
 * расчёт закрыт. «Доставки» нет: где машина физически — это вывоз (`Offer::position(Track::Service)`).
 * Значение `repair` — прежнее «Чинится».
 */
enum CarState: string
{
    use HasLabels;

    case Waiting = 'waiting';     // машина ещё не у менеджера: её везёт вывоз
    case Repair = 'repair';       // у менеджера, идёт подготовка (бумаги со страховой могут ещё идти)
    case Ready = 'ready';         // подготовлена, ждёт закрытия сделки со страховой
    case Selling = 'selling';     // сделка закрыта, менеджер ищет покупателя
    case Sold = 'sold';           // продана, расчёт ещё не закрыт
    case Settled = 'settled';     // счёт выставлен и оплачен

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Ждёт машину',
            self::Repair => 'Подготовка',
            self::Ready => 'Готова',
            self::Selling => 'В продаже',
            self::Sold => 'Продана',
            self::Settled => 'Расчёт закрыт',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Waiting => 'plain',
            self::Repair, self::Ready, self::Selling => 'open',
            self::Sold => 'urgent',
            self::Settled => 'closed',
        };
    }

    /** Порядок этапов: группы списка и сортировка идут им. */
    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** Следующий этап, на который менеджер переводит кнопкой, и сама кнопка. В продажу «Готова» встаёт сама. */
    public function advance(): ?array
    {
        return match ($this) {
            self::Repair => [self::Ready, 'Готова'],
            default => null,
        };
    }

    /** Машина у менеджера и не продана: расходы пишутся, сотрудник двигает этапы в обе стороны. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Repair, self::Ready, self::Selling], true);
    }

    /** Этапы дорожки «Гараж» — подготовка. */
    public function isPrep(): bool
    {
        return in_array($this, [self::Waiting, self::Repair, self::Ready], true);
    }

    /** Этапы, что дорожка «Продажа» показывает после маршрута со страховой. */
    public static function sale(): array
    {
        return [self::Selling, self::Sold, self::Settled];
    }

    /** Этапы дорожки «Гараж». */
    public static function prep(): array
    {
        return [self::Waiting, self::Repair, self::Ready];
    }
}
