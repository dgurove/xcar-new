<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Админам: страховая прислала счёт по идущей сделке (10.11.2026, X6 0790/046/09068/26: Альфа выставила счёт 09.11 — «оплата в
 * течение пяти рабочих дней», — письмо полтора дня лежало в «Разобрать ответ», а покупатель тем временем заплатил по счёту,
 * который ему показали мимо системы). Менеджер счёта не видит, пока админ не покажет (`Offers\SupplierInvoice`), — громко и в
 * Telegram. Остальные ответы страховой — тихо, как прежде.
 */
final class SupplierInvoiceArrivedNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'Счёт от страховой';
    }

    public function title(): string
    {
        return ($this->deal->offer->vendor?->name ?? 'Страховая').' выставила счёт — '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return 'Покажите счёт менеджеру'.($this->deal->buyer ? ' — '.$this->deal->buyer->shortName() : '').': «Разобрать ответ»';
    }

    public function href(): string
    {
        return '/work/deals/'.$this->deal->id;
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function subject(): string
    {
        return '/work/deals/'.$this->deal->id;
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
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть сделку'];
    }
}
