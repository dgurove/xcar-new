<?php

namespace App\Logistics\Actions;

use App\Cars\Car;
use App\Cars\Vin\Vin;
use App\Logistics\Channel;
use App\Logistics\Client;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Support\Money;
use App\Support\Phone;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Заявка логиста от своего заказчика (18.10.2026, владелец: «ей нужно удобно создавать заявки на вывоз от других, не только
 * от xcar»): заказчик, машина, откуда и куда с контактами на месте, когда, цена заказчику, исполнитель и его цена. Машина —
 * своя запись `Car` без продажи и хранения (с машинами XCar не склеивается: дорожек у неё нет). Задача — та же строка
 * `transports` (`origin = order`): дальше всё как у вывоза XCar — созвон, «Забрал» с фото, «Привёз», страница исполнителя
 * по ссылке. Исполнитель есть — `Assigned` и ему уведомление; нет — `Logist`, её ход. Правка — та же дверь: машину,
 * адреса, деньги — пока задача открыта, исполнителя — пока машину не забрали.
 */
final class OpenOrder
{
    public static function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'model_id' => ['nullable', 'integer', 'exists:car_models,id'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'plate' => ['nullable', 'string', 'max:20'],
            'vin' => ['nullable', 'string', 'max:30'],
            'from_address' => ['required', 'string', 'max:500'],
            'from_contact' => ['nullable', 'string', 'max:120'],
            'from_phone' => ['nullable', 'string', 'max:40'],
            'pickup_at' => ['nullable', 'date'],
            'to_address' => ['required', 'string', 'max:500'],
            'to_contact' => ['nullable', 'string', 'max:120'],
            'to_phone' => ['nullable', 'string', 'max:40'],
            'client_price' => ['nullable', 'string', 'max:20'],
            'executor_id' => ['nullable', 'integer'],
            'executor_cost' => ['nullable', 'string', 'max:20'],
            'distance_km' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'client_id.required' => 'Выберите заказчика', 'brand_id.required' => 'Марка',
            'from_address.required' => 'Откуда забрать', 'to_address.required' => 'Куда везти',
        ];
    }

    public function __invoke(array $data, User $by, ?Transport $t = null): Transport
    {
        if (! $by->isLogist() || ($t && (! $t->isOrder() || ! $t->hasLogist($by)))) {
            abort(404);
        }
        if ($t && ! $t->isOpen()) {
            throw ValidationException::withMessages(['client_id' => 'Заявка закрыта']);
        }
        $client = Client::of($by)->whereNull('archived_at')->find($data['client_id']);
        if (! $client) {
            throw ValidationException::withMessages(['client_id' => 'Выберите заказчика']);
        }
        $who = ($data['executor_id'] ?? null) ? User::find($data['executor_id']) : null;
        if ($who && ($who->rejected_at || ! ($who->isContractor() && $who->logist_id === $by->id))) {
            throw ValidationException::withMessages(['executor_id' => 'Выберите своего исполнителя']);
        }
        if ($t && $t->state->picked() && $t->executor_id !== $who?->id) {
            throw ValidationException::withMessages(['executor_id' => 'ТС уже забрали: исполнителя не поменять']);
        }
        $vin = Vin::normalize($data['vin'] ?? null);
        if ($vin && strlen($vin) !== 17) {
            throw ValidationException::withMessages(['vin' => 'VIN — 17 знаков']);
        }

        return DB::transaction(function () use ($data, $by, $t, $client, $who, $vin) {
            $car = $t?->car ?? new Car;
            $car->fill([
                'brand_id' => $data['brand_id'], 'model_id' => $data['model_id'] ?? null, 'year' => $data['year'] ?? null,
                'plate' => $data['plate'] ?? null, 'vin' => $vin,
            ])->save();

            $t ??= new Transport(['origin' => 'order', 'kind' => TransportKind::Pickup, 'channel' => Channel::Logist,
                'logist_id' => $by->id, 'created_by' => $by->id, 'destination' => Point::Other, 'state' => TransportState::Logist]);
            $fresh = ! $t->exists;
            $previous = $t->exists ? $t->executor : null;
            $changed = $t->executor_id !== $who?->id;
            $t->fill([
                'car_id' => $car->id, 'client_id' => $client->id,
                'from_address' => trim($data['from_address']), 'from_contact' => trim((string) ($data['from_contact'] ?? '')) ?: null,
                'from_phone' => Phone::normalize($data['from_phone'] ?? null),
                'to_address' => trim($data['to_address']), 'to_contact' => trim((string) ($data['to_contact'] ?? '')) ?: null,
                'to_phone' => Phone::normalize($data['to_phone'] ?? null),
                'pickup_at' => $data['pickup_at'] ?? null ?: null,
                'client_price' => Money::rubles($data['client_price'] ?? null),
                'executor_cost' => Money::rubles($data['executor_cost'] ?? null),
                'distance_km' => $data['distance_km'] ?? null ?: null,
                'comment' => trim((string) ($data['comment'] ?? '')) ?: null,
            ]);
            if ($changed) {
                $t->fill([
                    'executor_id' => $who?->id,
                    'assigned_at' => $who ? now() : null,
                    'state' => match (true) {
                        ! $who => TransportState::Logist,
                        $t->agreed_at !== null => TransportState::Agreed,
                        default => TransportState::Assigned,
                    },
                ]);
            }
            $t->save();
            if ($fresh) {
                TransportEvent::record($t, 'opened', $by);
            }
            if ($changed) {
                TransportEvent::record($t, $who ? 'assigned' : 'released', $by, $who ? ['executor' => $who->id, 'was' => $previous?->id] : []);
            }
            if ($changed && $who && ! $who->is($by)) {
                TransportAssigned::dispatch($t->load('executor'), $previous);
            }

            return $t;
        });
    }
}
