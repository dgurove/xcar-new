<?php

namespace App\Notifications;

use App\Offers\ExtraAsk;
use App\Telegram\Text;

/**
 * Менеджеру: поставщик ответил на доп. просьбу, ответ показали — решить, берёт ли (18.11.2026, `Offers\ExtraAsk`, владелец: «он окнул
 * и сразу нажал „Беру в работу“ либо отказ»). Что ответили — сообщением в чат сделки (`Disclose`), здесь — вопрос и кнопки; в
 * Telegram — кнопками в сообщении (`UpdateHandler::extra`).
 */
final class ExtraAskDecisionNotice extends Notice
{
    public function __construct(private ExtraAsk $ask) {}

    /** Решили раньше, чем очередь дошла, — не шлём. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ExtraAsk::whereKey($this->ask->id)->whereNull('decided_at')->exists() && parent::shouldSend($notifiable, $channel);
    }

    public function label(): string
    {
        return 'Ход в сделке';
    }

    public function title(): string
    {
        return self::question($this->ask).', № '.$this->ask->offer->number;
    }

    public function text(): ?string
    {
        return 'Поставщик ответил на просьбу: '.$this->ask->phrase();
    }

    public function href(): string
    {
        return $this->ask->deal?->href() ?? "/deals/{$this->ask->deal_id}";
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
        return true;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => self::question($this->ask).': '.$this->ask->offer->titleWithYear(), 'lines' => Text::lines($this->ask->offer, 'Поставщик ответил на просьбу: '.$this->ask->phrase()),
            'button' => 'Открыть сделку', 'keyboard' => [self::buttons($this->ask)]];
    }

    /** До согласия — «Берёте в работу?», после — «Берёте после ответа поставщика?». */
    public static function question(ExtraAsk $ask): string
    {
        return $ask->deal && ExtraAsk::consented($ask->deal) ? 'Поставщик ответил, нужно решение' : 'Поставщик ответил, взять в работу?';
    }

    /** @return list<array{text: string, callback_data: string}> */
    public static function buttons(ExtraAsk $ask): array
    {
        $labels = $ask->deal ? ExtraAsk::labels($ask->deal) : ['take' => 'Беру в работу', 'drop' => 'Я передумал'];

        return [['text' => $labels['take'], 'callback_data' => "extra:{$ask->id}:take"], ['text' => $labels['drop'], 'callback_data' => "extra:{$ask->id}:drop"]];
    }
}
