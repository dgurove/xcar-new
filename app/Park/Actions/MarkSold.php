<?php

namespace App\Park\Actions;

use App\Mail\Message;
use App\Park\Events\VehicleSold;
use App\Park\EventType;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Страховая продала ТС: с даты письма идут дни за счёт вендора, дальше платит покупатель; кому выдать — из письма.
 * Тут же заявка на выдачу с контактом покупателя — она и попадает в пресет «Выдача» заявок. Повтор — правка полей.
 */
final class MarkSold
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Vehicle $vehicle, ?User $by, CarbonInterface $soldAt, ?string $name, ?string $phone, ?Message $message = null): Vehicle
    {
        $fresh = ! $vehicle->sold_at;
        Nav::forgetStaffCounts();
        DB::transaction(function () use ($vehicle, $by, $soldAt, $name, $phone, $message, $fresh) {
            $vehicle->update(array_filter([
                'sold_at' => $soldAt->toDateString(), 'sold_message_id' => $message?->id,
                'pickup_name' => $name, 'pickup_phone' => $phone,
            ], fn ($v) => $v !== null) + ($fresh ? [] : ['pickup_name' => $name, 'pickup_phone' => $phone]));
            if ($vehicle->buyerParty && $name) {
                $vehicle->buyerParty->update(['name' => $name, 'phone' => $phone]);
            }
            $vehicle->log($fresh ? EventType::Sold : EventType::Updated, $by, $fresh
                ? array_filter(['who' => trim(($name ?? '').' '.($phone ?? '')) ?: null, 'thread' => $message?->thread_id])
                : ['fields' => ['sold_at', 'pickup_name']]);
            if ($fresh && $vehicle->state === VehicleState::Stored && ! $vehicle->openRequest(RequestType::Release)) {
                ($this->create)($by, RequestType::Release, $vehicle,
                    ['contact_name' => $name, 'contact_phone' => $phone, 'thread_id' => $message?->thread_id]);
            }
        });
        if ($fresh) {
            VehicleSold::dispatch($vehicle->fresh(), $message);
        }

        return $vehicle;
    }

    /**
     * Продажи не было: покупатель отказался или её записали по ошибке. Дни снова считаются страховой по обычной
     * ставке (`Accrual::buyerFrom` без `sold_at` молчит), незанятая заявка на выдачу снимается.
     */
    public function clear(Vehicle $vehicle, ?User $by, string $reason): void
    {
        Nav::forgetStaffCounts();
        DB::transaction(function () use ($vehicle, $by, $reason) {
            $vehicle->update(['sold_at' => null, 'sold_message_id' => null, 'pickup_name' => null, 'pickup_phone' => null, 'buyer_party_id' => null]);
            $vehicle->requests()->where('type', RequestType::Release)->whereIn('state', [RequestState::New, RequestState::Scheduled, RequestState::InProgress])
                ->update(['state' => RequestState::Cancelled, 'done_at' => now(), 'done_by' => $by?->id, 'cancel_reason' => $reason]);
        });
    }
}
