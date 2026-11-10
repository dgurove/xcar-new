<?php

namespace App\Notifications;

use App\Offers\RepriceAsk;
use App\Support\Ago;
use App\Telegram\Text;

/**
 * Менеджеру: новая закупочная — согласен ли он (28.10.2026, `RepriceAsk`; с 03.11.2026 в любую сторону и только решением админа,
 * цифрами, ни слова письма). Важное и мимо категорий (`critical`), как
 * контрпредложение: без ответа у него прежняя цена, а у нас горит. С 10.11.2026 ответ — его новая цена подтверждения: в
 * Telegram «Ввести цену» ведёт на страницу, «Отказываюсь» — с переспросом (`UpdateHandler::reprice`). Ответили раньше доставки
 * (за него админ, новая переоценка) — не шлём.
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
        return 'Новая закупочная';
    }

    public function title(): string
    {
        return self::telegramTitle($this->ask);
    }

    public function text(): ?string
    {
        $p = $this->ask->pairs();

        return 'Было '.$p['was'].', стало '.$p['now'].'. Назовите новую цену подтверждения до '.Ago::day($this->ask->due_at->setTimezone('Europe/Moscow'), true);
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
            'keyboard' => [self::buttons($this->ask)], 'button' => 'Ввести цену'];
    }

    /** Цену вводят на странице («Ввести цену» — кнопка-ссылка), здесь — только отказ. @return list<array{text: string, callback_data: string}> */
    public static function buttons(RepriceAsk $ask): array
    {
        return [['text' => 'Отказываюсь', 'callback_data' => "reprice:{$ask->id}:no"]];
    }

    public static function telegramTitle(RepriceAsk $ask): string
    {
        // Слово «закупочная» — только где она у него на экране (`RepriceAsk::shownAs`).
        return ($ask->shownAs() === 'reward' ? 'Вознаграждение изменится: ' : 'Новая цена: ').$ask->offer->titleWithYear();
    }

    /** @return array<int, mixed> */
    public static function telegramLines(RepriceAsk $ask): array
    {
        $p = $ask->pairs();

        return Text::lines($ask->offer, 'Было: '.$p['was'], 'Стало: '.$p['now'], 'Назовите новую цену подтверждения до '.Ago::day($ask->due_at->setTimezone('Europe/Moscow'), true));
    }
}
