<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Surface;
use Illuminate\Support\HtmlString;

/**
 * Письмо-предложение само стало черновиком (`AutoOffer`, 12.10.2026) — модераторам с галкой почты вместо «Новое из
 * писем»: заводить уже нечего, а закупочную и поля проверить надо. Ведёт в черновик. Только строкой ленты — без пуша и
 * Telegram (07.11.2026, владелец: «глуши»; по журналу доставки открыли 0 из ~70 за неделю — работу берут из списка).
 */
final class OfferDraftNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function label(): string
    {
        return 'Черновик из письма';
    }

    public function title(): string
    {
        return 'Черновик из письма: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return implode(', ', array_filter([$this->offer->vendor?->name, $this->offer->claim_ref])) ?: null;
    }

    public function href(): string
    {
        return Surface::Crm->url('/offers/'.$this->offer->number);
    }

    public function quiet(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'letters';
    }

    public function toTelegram(): ?array
    {
        return [
            'title' => $this->title(),
            'lines' => [$this->offer->vin ? new HtmlString('VIN <code>'.e($this->offer->vin).'</code>') : null, $this->text()],
            'button' => 'Открыть в CRM',
        ];
    }
}
