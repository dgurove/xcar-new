<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Surface;

/**
 * Админам по отказу менеджера и авто-снятию после него (19.11.2026, начальник): ТС снято и поставщику ушло «не актуально», или
 * письмо уйти не смогло — написать самим. Громко, в ленту и Telegram (`Notify::toStaff`).
 */
final class RefusalNotice extends Notice
{
    public function __construct(private Offer $offer, private string $what, private ?string $detail = null) {}

    public function label(): string
    {
        return 'Менеджер отказался';
    }

    public function title(): string
    {
        return $this->what.': '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->detail;
    }

    public function href(): string
    {
        return Surface::Crm->url('/offers/'.$this->offer->number);
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function important(): bool
    {
        return true;
    }
}
