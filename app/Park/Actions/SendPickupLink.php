<?php

namespace App\Park\Actions;

use App\Mail\Message;
use App\Park\Events\VehicleSold;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;

/**
 * Страховая написала «продано» — у вендора с выдачей по QR сразу отвечаем в ту же переписку: выдача только по QR,
 * вот ссылка для покупателя. Один раз на продажу (`VehicleSold` приходит только на новую); руками — «Отправить ссылку
 * ещё раз» в деле ТС.
 */
final class SendPickupLink
{
    public function __construct(private ParkLetter $letters) {}

    public function handle(VehicleSold $e): void
    {
        // Только на свежее «продано»: ТС, заведённые задним числом по старым письмам (StoreByLetters, загрузка истории
        // ящика), тоже проходят через MarkSold — отвечать страховой на письмо полугодовой давности незачем.
        if ($e->message && $e->message->date_at?->gt(now()->subDays(7)) && $e->vehicle->releasesByQr() && $e->vehicle->state === VehicleState::Stored) {
            $this->send($e->vehicle, $e->message);
        }
    }

    public function send(Vehicle $vehicle, ?Message $parent = null, ?User $by = null): bool
    {
        $message = $this->letters->toVendor($vehicle, 'pickup-link', ['pickup_link' => $vehicle->pickupUrl()], $parent);
        if ($message) {
            $vehicle->log(EventType::PickupLinkSent, $by, ['thread' => $message->thread_id]);
        }

        return (bool) $message;
    }
}
