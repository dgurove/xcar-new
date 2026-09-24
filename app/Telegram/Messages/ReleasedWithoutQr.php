<?php

namespace App\Telegram\Messages;

use App\Park\Vehicle;
use App\Support\Surface;
use App\Users\User;

/** У вендора с выдачей по QR выдали без кода: кто и почему. */
final class ReleasedWithoutQr extends Message
{
    public function __construct(private Vehicle $vehicle, private User $by, private string $reason) {}

    protected function title(): string
    {
        return 'Выдана без QR';
    }

    protected function lines(): array
    {
        $v = $this->vehicle;

        return [
            $v->titleWithYear().($v->ref ? ', '.$v->ref : ''),
            $v->vendor?->name,
            'Выдал '.$this->by->name,
            'Причина: '.$this->reason,
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'ТС на парковке', 'url' => Surface::Park->url('/cars/'.$this->vehicle->id)];
    }
}
