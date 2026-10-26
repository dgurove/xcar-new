<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Support\Money;
use App\Telegram\Text;

/** Менеджеру: контрпредложение на его подтверждение (26.10.2026) — важное, с кнопкой на машину, где он ответит. */
final class CounterOfferedNotice extends Notice
{
    public function __construct(private Bid $bid) {}

    public function title(): string
    {
        return 'Контрпредложение '.Money::plain($this->bid->counter_amount).': № '.$this->bid->offer->number;
    }

    public function text(): ?string
    {
        return $this->bid->offer->titleWithYear().', ваша цена '.Money::plain($this->bid->amount);
    }

    public function href(): string
    {
        return '/offers/'.$this->bid->offer->number;
    }

    public function offerNumber(): ?int
    {
        return $this->bid->offer->number;
    }

    public function category(): string
    {
        return 'bids';
    }

    public function critical(): bool
    {
        return true;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        $offer = $this->bid->offer;

        return ['title' => self::telegramTitle($this->bid),
            'lines' => self::telegramLines($this->bid),
            // Ответ прямо из бота (`UpdateHandler::counter`): «Согласен» переспрашивает — согласие сразу открывает сделку.
            'keyboard' => [[['text' => 'Согласен', 'callback_data' => "counter:{$this->bid->id}:yes"], ['text' => 'Отказаться', 'callback_data' => "counter:{$this->bid->id}:no"]]],
            'button' => 'Открыть'];
    }

    public static function telegramTitle(Bid $bid): string
    {
        return 'Контрпредложение: '.$bid->offer->titleWithYear();
    }

    /** @return array<int, mixed> */
    public static function telegramLines(Bid $bid): array
    {
        return Text::lines($bid->offer, Money::rub($bid->counter_amount).' вместо '.Money::rub($bid->amount), $bid->counter_comment);
    }
}
