<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportRequested;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «В логистику» из дела ТС на парковке (10.10.2026): перевод на другую парковку или по адресу — задача логистики без
 * созвона и документов (`TransportKind::Move`). Кто везёт, выбирает админ в логистике; «Привёз» переставляет ТС
 * (`Deliver::toYard`). Открытая у ТС одна — повторная отправка правит точку.
 */
final class OpenMove
{
    public static function rules(): array
    {
        return [
            'to_yard_id' => ['nullable', 'integer', Rule::exists('park_yards', 'id')],
            'to_address' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function __invoke(Vehicle $vehicle, array $data, User $by): Transport
    {
        if (! in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true)) {
            throw ValidationException::withMessages(['to_yard_id' => 'Везти можно ТС на парковке или ожидаемую']);
        }
        $yard = $data['to_yard_id'] ?? null ?: null;
        $address = trim((string) ($data['to_address'] ?? '')) ?: null;
        if (! $yard && ! $address) {
            throw ValidationException::withMessages(['to_yard_id' => 'Куда: парковка или адрес']);
        }
        $t = Transport::openFor($vehicle);
        if ($t?->state->picked()) {
            throw ValidationException::withMessages(['to_yard_id' => 'ТС уже везут']);
        }
        $fresh = ! $t;
        $t ??= new Transport(['car_id' => $vehicle->car_id, 'origin' => 'park', 'kind' => TransportKind::Move, 'state' => TransportState::New, 'created_by' => $by->id]);
        $t->fill(['from_address' => $vehicle->yard?->fullAddress(), 'destination' => $yard ? Point::Yard : Point::Other, 'to_yard_id' => $yard, 'to_address' => $address])->save();
        if ($fresh) {
            $vehicle->log(EventType::Note, $by, ['text' => 'Логистика: перевозка '.($t->toYard?->name ?? $address)]);
            TransportRequested::dispatch($t);
        }

        return $t;
    }
}
