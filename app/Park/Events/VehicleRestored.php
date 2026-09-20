<?php

namespace App\Park\Events;

use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** ТС снова ожидается: приём отменён или «Не привезена» передумали — CRM возвращает «у владельца». */
final class VehicleRestored
{
    use Dispatchable;

    public function __construct(public Vehicle $vehicle, public ?User $by = null) {}
}
