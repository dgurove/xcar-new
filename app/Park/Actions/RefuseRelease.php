<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Покупатель приехал, посмотрел и не взял. Продажа снимается (дни снова считаются страховой по обычной ставке,
 * заявка на выдачу закрывается), ТС остаётся стоять и ждёт нового покупателя — его пришлёт письмо страховой.
 * Дальше сотруднику открывается черновик письма вендору по шаблону «Отказ от получения».
 */
final class RefuseRelease
{
    public function __construct(private MarkSold $sold) {}

    public function __invoke(Vehicle $vehicle, ?User $by, string $reason): void
    {
        if ($vehicle->state !== VehicleState::Stored) {
            throw ValidationException::withMessages(['reason' => 'ТС не на парковке']);
        }
        Nav::forgetStaffCounts();
        $vehicle->log(EventType::ReleaseRefused, $by, ['note' => $reason]);
        $this->sold->clear($vehicle, $by, 'Покупатель отказался');
    }
}
