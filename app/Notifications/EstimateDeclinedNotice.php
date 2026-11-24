<?php

namespace App\Notifications;

use App\Telegram\Text;
use App\Offers\Estimate;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

/** Менеджер отказался от оценки (11.10.2026) — админам тихой строкой: кто, сколько хочет; ведёт в окошко оценок. */
final class EstimateDeclinedNotice extends Notice
{
    public function __construct(private Estimate $estimate) {}

    public function label(): string
    {
        return 'Отказ от оценки';
    }

    public function title(): string
    {
        return $this->estimate->user->shortName().': отказ от оценки, хочет '.Money::rub($this->estimate->wish_price);
    }

    public function text(): ?string
    {
        return $this->estimate->offer->titleWithYear();
    }

    public function href(): string
    {
        return Surface::Crm->url('/offers?'.http_build_query(['vid' => 'table', 'preset' => match (true) { $this->estimate->isCorrection() => 'priced', $this->estimate->offer->onEstimate() => 'estimating', default => 'unpriced' }, 'peek' => $this->estimate->offer->number]));
    }

    public function category(): string
    {
        return 'estimates';
    }

    /**
     * Админу — пушем и в Telegram, категорией «Оценки менеджеров» (18.11.2026, начальник: всё по сделкам — в центр уведомлений
     * и в Telegram; владелец: категории включены, выключают в настройках). Было тихой строкой ленты (07.11.2026). Без звука.
     */
    public function important(): bool
    {
        return false;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text(), ...Text::lines($this->estimate->offer)], 'button' => 'Открыть'];
    }

    public function actor(): ?User
    {
        return $this->estimate->user;
    }
}
