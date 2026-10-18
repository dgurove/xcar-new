<?php

namespace App\Park\Listeners;

use App\Park\Actions\CreateRequest;
use App\Park\Events\VehicleAccepted;
use App\Park\RequestType;

/**
 * Продали раньше, чем машину привезли (Альфа оплатила сделку, `HandToParking`; письмо «продано» до приёма): заявку на
 * выдачу при продаже завести было нельзя (`RequestType::allowedFor`) — она встаёт при приёме, с тем, кто заберёт (18.10.2026).
 */
final class ReleaseSold
{
    public function __construct(private CreateRequest $create) {}

    public function handle(VehicleAccepted $e): void
    {
        $vehicle = $e->vehicle->fresh();
        if (! $vehicle?->sold_at || $vehicle->openRequest(RequestType::Release)) {
            return;
        }
        ($this->create)($e->by, RequestType::Release, $vehicle, ['contact_name' => $vehicle->pickup_name, 'contact_phone' => $vehicle->pickup_phone]);
    }
}
