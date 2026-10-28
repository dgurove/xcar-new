<?php

namespace App\Notifications;

use App\Offers\Handover;
use App\Telegram\Text;
use App\Workflow\Outcome;
use App\Workflow\Requirement;

/** Менеджеру: сделка ждёт его ответа. */
final class YourTurnNotice extends Notice
{
    public function __construct(private Requirement $requirement) {}

    /**
     * Просьбу закрыли раньше, чем очередь дошла (ответили за менеджера, «Уже согласился» у «Спросить менеджера»,
     * 28.10.2026) — не шлём: иначе ему «Беру в работу?» о том, на что уже ответили.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Requirement::whereKey($this->requirement->id)->whereNull('done_at')->exists() && parent::shouldSend($notifiable, $channel);
    }

    public function title(): string
    {
        return $this->requirement->title.' — № '.$this->requirement->offer->number;
    }

    public function text(): ?string
    {
        $due = $this->requirement->due_at ? ' До '.$this->requirement->due_at->translatedFormat('j M, H:i').'.' : '';

        return trim(($this->requirement->text ?? '').$due) ?: null;
    }

    public function href(): string
    {
        return $this->requirement->deal?->href() ?? "/deals/{$this->requirement->deal_id}";
    }

    public function offerNumber(): ?int
    {
        return $this->requirement->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }

    /** Согласие взять сделку (Альфа) — со звуком до нажатия: после письма поставщику «актуально» отказаться нельзя. */
    public function important(): bool
    {
        return $this->consent();
    }

    public function toTelegram(): ?array
    {
        $r = $this->requirement;

        // Заголовок — что сделать, а не «Ваш ход» (10.10.2026, владелец: проверить формулировки в Telegram).
        return ['title' => self::telegramTitle($r), 'lines' => self::telegramLines($r), 'button' => 'Открыть сделку',
            // Согласие — кнопками прямо в сообщении (27.10.2026, владелец), ответ — `UpdateHandler::turn`.
            ...($this->consent() ? ['keyboard' => [self::consentButtons($r)]] : [])];
    }

    public static function telegramTitle(Requirement $r): string
    {
        return $r->title.': '.$r->offer->titleWithYear();
    }

    /** @return list<string|null> */
    public static function telegramLines(Requirement $r): array
    {
        return Text::lines($r->offer, $r->due_at ? 'До '.$r->due_at->translatedFormat('j M, H:i') : null);
    }

    /** «Беру в работу» и «Передумал» — у кого из них есть исход на этом этапе. */
    public static function consentButtons(Requirement $r): array
    {
        return array_values(array_filter([
            self::consentExit($r, 'take') ? ['text' => 'Беру в работу', 'callback_data' => "turn:{$r->id}:take"] : null,
            self::consentExit($r, 'drop') ? ['text' => 'Передумал', 'callback_data' => "turn:{$r->id}:drop"] : null,
        ]));
    }

    /** Исход согласия: take — «Беру в работу», drop — «Я передумал». */
    public static function consentExit(Requirement $r, string $which): ?Outcome
    {
        $exits = $r->deal ? Handover::managerExits($r->stage, $r->deal) : collect();

        return $exits->first(fn (Outcome $e) => $which === 'take' ? $e->takes() : $e->isRetreat());
    }

    private function consent(): bool
    {
        return (bool) $this->requirement->stage?->asksConsent();
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
