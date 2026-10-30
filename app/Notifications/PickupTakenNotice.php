<?php

namespace App\Notifications;

use App\Logistics\Transport;
use App\Offers\Offer;

/**
 * Менеджеру (19.10.2026, владелец: «в логистике админ должен мочь снять или сменить исполнителя — тогда менеджер уже не
 * сам вывозит»): его вывоз забрала логистика — ехать за машиной не нужно. Кто везёт — словами, если уже назначен.
 */
final class PickupTakenNotice extends Notice
{
    public function __construct(private Transport $transport, private Offer $offer, private string $href) {}

    public function label(): string
    {
        return 'Вывоз передан в логистику';
    }

    public function title(): string
    {
        return 'Вывоз передан в логистику: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $carrier = $this->transport->executor ?? $this->transport->logist;

        return 'Забирать самому не нужно'.($carrier ? ', везёт '.$carrier->shortName() : '');
    }

    public function href(): string
    {
        return $this->href;
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть'];
    }
}
