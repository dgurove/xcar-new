<?php

namespace App\Telegram\Messages;

use App\Park\Vehicle;
use App\Support\Surface;
use App\Users\User;

/** Управляющий принял или выдал ТС — администраторам парковки (о заявках им не пишем, владелец 09.10.2026). */
final class ParkHandled extends Message
{
    public function __construct(private Vehicle $vehicle, private User $by, private bool $released) {}

    protected function title(): string
    {
        return $this->by->shortName().' '.($this->released ? 'выдал' : 'принял');
    }

    protected function lines(): array
    {
        $v = $this->vehicle;

        return [
            $v->titleWithYear().($v->plate ? ', '.$v->plate : ''),
            implode(', ', array_filter([$v->vendor?->name, $v->ref])),
            $v->yard?->name,
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
