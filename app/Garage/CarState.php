<?php

namespace App\Garage;

use App\Cars\HasLabels;

/**
 * Этап машины в гараже. Ждёт страховую — идёт маршрут вендора (сделка), двигаем мы; дальше двигает менеджер:
 * «Привёз», «Готова», «Продаю». Значение `repair` — прежнее «Чинится», теперь «Подготовка».
 */
enum CarState: string
{
    use HasLabels;

    case Waiting = 'waiting';     // принято, маршрут со страховой ещё идёт
    case Delivery = 'delivery';   // можно забирать, едет к менеджеру
    case Repair = 'repair';       // у менеджера, идёт подготовка
    case Selling = 'selling';     // готова, менеджер ищет покупателя
    case Sold = 'sold';           // продана, расчёт ещё не закрыт
    case Settled = 'settled';     // счёт выставлен и оплачен

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Ждёт страховую',
            self::Delivery => 'Доставка',
            self::Repair => 'Подготовка',
            self::Selling => 'В продаже',
            self::Sold => 'Продана',
            self::Settled => 'Расчёт закрыт',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Waiting => 'plain',
            self::Delivery, self::Repair, self::Selling => 'open',
            self::Sold => 'urgent',
            self::Settled => 'closed',
        };
    }

    /** Порядок этапов: группы списка и сортировка идут им. */
    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** Следующий этап, на который менеджер переводит кнопкой, и сама кнопка. */
    public function advance(): ?array
    {
        return match ($this) {
            self::Delivery => [self::Repair, 'Привёз'],
            self::Repair => [self::Selling, 'Готова'],
            default => null,
        };
    }

    /** Пока машина у менеджера и не продана: расходы пишутся, «Отдали по ошибке» уже нельзя без отмены расходов. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Delivery, self::Repair, self::Selling], true);
    }
}
