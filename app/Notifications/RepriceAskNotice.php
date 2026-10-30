<?php

namespace App\Notifications;

use App\Offers\RepriceAsk;
use App\Telegram\Text;

/**
 * Менеджеру: закупочная выросла — согласен ли он (28.10.2026, `RepriceAsk`). Важное и мимо категорий (`critical`), как
 * контрпредложение: без ответа у него прежняя цена, а у нас горит. В Telegram — «Согласен» / «Отказываюсь» с переспросом
 * (`UpdateHandler::reprice`). Ответили раньше доставки (за него админ, новая переоценка) — не шлём.
 */
final class RepriceAskNotice extends Notice
{
    public function __construct(private RepriceAsk $ask) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return (bool) RepriceAsk::find($this->ask->id)?->alive() && parent::shouldSend($notifiable, $channel);
    }

    public function label(): string
    {
        return 'Закупочная выросла';
    }

    public function title(): string
    {
        return self::telegramTitle($this->ask);
    }

    public function text(): ?string
    {
        return $this->ask->phrase().'. Согласны? Ответьте до '.$this->ask->due_at->setTimezone('Europe/Moscow')->translatedFormat('j M, H:i');
    }

    public function href(): string
    {
        return $this->ask->deal_id ? $this->ask->deal->href() : '/offers/'.$this->ask->offer->number;
    }

    public function offerNumber(): ?int
    {
        return $this->ask->offer->number;
    }

    public function category(): string
    {
        return $this->ask->deal_id ? 'deals' : 'bids';
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
        return ['title' => self::telegramTitle($this->ask), 'lines' => self::telegramLines($this->ask),
            'keyboard' => [self::buttons($this->ask)], 'button' => 'Открыть'];
    }

    /** @return list<array{text: string, callback_data: string}> */
    public static function buttons(RepriceAsk $ask): array
    {
        return [['text' => 'Согласен', 'callback_data' => "reprice:{$ask->id}:yes"], ['text' => 'Отказываюсь', 'callback_data' => "reprice:{$ask->id}:no"]];
    }

    public static function telegramTitle(RepriceAsk $ask): string
    {
        // Слово «закупочная» — только где она у него на экране (`RepriceAsk::shownAs`).
        return ($ask->shownAs() === 'reward' ? 'Вознаграждение уменьшится: ' : 'Новая цена от поставщика: ').$ask->offer->titleWithYear();
    }

    /** @return array<int, mixed> */
    public static function telegramLines(RepriceAsk $ask): array
    {
        return Text::lines($ask->offer, $ask->phrase(), 'Согласны? Ответьте до '.$ask->due_at->setTimezone('Europe/Moscow')->translatedFormat('j M, H:i'));
    }
}
