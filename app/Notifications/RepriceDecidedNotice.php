<?php

namespace App\Notifications;

use App\Offers\RepriceAsk;
use App\Support\Ago;
use App\Support\Money;
use App\Telegram\Text;

/**
 * Менеджеру: что мы решили по его новой цене в сделке (10.11.2026, `AnswerReprice`): приняли, контрпредложение (ответить —
 * его ход, важное) или отказали (сделка отменена). Без слова «закупочная» — только его цена.
 */
final class RepriceDecidedNotice extends Notice
{
    public const ACCEPTED = 'accepted';

    public const COUNTERED = 'countered';

    public const REJECTED = 'rejected';

    public function __construct(private RepriceAsk $ask, private string $what) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Контрпредложение, на которое уже ответили, — не шлём.
        return ($this->what !== self::COUNTERED || RepriceAsk::find($this->ask->id)?->isCountered()) && parent::shouldSend($notifiable, $channel);
    }

    public function label(): string
    {
        return 'Решение по новой цене';
    }

    public function title(): string
    {
        $car = $this->ask->offer->titleWithYear();

        return match ($this->what) {
            self::ACCEPTED => 'Цена принята: '.$car,
            self::COUNTERED => 'Контрпредложение: '.$car,
            default => 'Сделка отменена: '.$car,
        };
    }

    public function text(): ?string
    {
        return match ($this->what) {
            self::ACCEPTED => 'Ваша цена '.Money::rub((int) $this->ask->offered_price).', счёт и договор обновятся',
            self::COUNTERED => 'Предлагаем '.Money::rub((int) $this->ask->counter_price).' вместо '.Money::rub((int) $this->ask->offered_price).'. Ответьте до '.Ago::day($this->ask->due_at->setTimezone('Europe/Moscow'), true),
            default => 'По цене '.Money::rub((int) $this->ask->offered_price).' не договорились',
        };
    }

    public function href(): string
    {
        return $this->what === self::REJECTED ? '/deals' : $this->ask->deal->href();
    }

    public function offerNumber(): ?int
    {
        return $this->ask->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return $this->what === self::COUNTERED;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->ask->offer, $this->text()), 'button' => $this->what === self::COUNTERED ? 'Ответить' : 'Открыть'];
    }
}
