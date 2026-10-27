<?php

namespace App\Park\Listeners;

use App\Offers\Actions\SyncParkRelease;
use App\Park\Actions\CreateRequest;
use App\Park\Events\VehicleAccepted;
use App\Park\RequestState;
use App\Park\RequestType;

/**
 * Продали раньше, чем машину привезли (сделка XCar — `SyncParkRelease`; письмо «продано» до приёма): заявку на
 * выдачу при продаже завести было нельзя (`RequestType::allowedFor`) — она встаёт при приёме, с тем, кто заберёт (18.10.2026).
 */
final class ReleaseSold
{
    public function __construct(private CreateRequest $create) {}

    public function handle(VehicleAccepted $e): void
    {
        $vehicle = $e->vehicle->fresh();
        // Машину сделки XCar привезли — выдача и пропуск по сделке, если её уже можно забирать.
        // Сделка — идущая у предложения или та, чей пропуск жив (гаражная уже закрыта, а машину только привезли).
        if ($deal = $vehicle?->dealPass()?->deal ?? $vehicle?->offer?->deal) {
            app(SyncParkRelease::class)($deal, $e->by);
            $vehicle->refresh();
        }
        if (! $vehicle?->sold_at || $vehicle->requests()->where('type', RequestType::Release)->whereIn('state', RequestState::open())->exists()) {
            return;
        }
        ($this->create)($e->by, RequestType::Release, $vehicle, ['contact_name' => $vehicle->pickup_name, 'contact_phone' => $vehicle->pickup_phone]);
    }
}
