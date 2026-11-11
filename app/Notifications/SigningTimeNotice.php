<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Offers\Signing;
use App\Telegram\Text;

/**
 * Менеджеру: когда покупатель сможет подписать договор (11.11.2026, `Offers\Actions\AskSigningTime`) — как только поставщик начал
 * готовить договор, чтобы на «договор готов» сразу написать ему про время. Ссылка — на шаг сделки с днями.
 */
final class SigningTimeNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    /** Ответил раньше, чем дошла очередь, — не шлём. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $deal = Deal::find($this->deal->id);

        return $deal && Signing::wishDue($deal) && parent::shouldSend($notifiable, $channel);
    }

    public function label(): string
    {
        return 'Когда покупатель подпишет договор';
    }

    public function title(): string
    {
        return 'Когда покупатель сможет подписать договор? № '.$this->deal->offer->number;
    }

    public function text(): ?string
    {
        return 'Поставщик готовит договор. Выберите день и время — сразу запишем';
    }

    public function href(): string
    {
        return $this->deal->href().'#step';
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => 'Когда покупатель сможет подписать договор: '.$this->deal->offer->titleWithYear(),
            'lines' => Text::lines($this->deal->offer, 'Поставщик готовит договор. Выберите день и время — сразу запишем'),
            'button' => 'Выбрать время'];
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
