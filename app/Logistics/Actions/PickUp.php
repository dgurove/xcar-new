<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Забрал» (17.10.2026, владелец): исполнитель снял приём — фото сколько угодно (машина, документы, что угодно; кладёт
 * их загрузчик заранее, `TransportController::upload`), сколько ключей (один — красный «!» у машины везде,
 * `x-car.keys`), куда везёт. Машина «Вывезена» — в CRM и на xcar.ru её место «в пути» (`CarPlace::Moving`); менеджер,
 * что везёт к себе в гараж сам, — сразу «у менеджера» (забрал — и привёз). Рано
 * (не созвонились, документы не дошли, Альфа до оплаты) — нельзя, кроме админа (`Transport::pickupBlocker`).
 */
final class PickUp
{
    public function __construct(private SetCarPlace $place) {}

    public static function rules(): array
    {
        return [
            'keys' => ['required', 'integer', 'min:0', 'max:9'],
            'destination' => ['required', Rule::enum(Point::class)],
            'to_yard_id' => ['nullable', 'integer', Rule::exists('park_yards', 'id')],
            'to_address' => ['nullable', 'string', 'max:500'],
            'pickup_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return ['keys.required' => 'Сколько ключей', 'destination.required' => 'Куда везёте'];
    }

    public function __invoke(Transport $t, array $data, User $by): Transport
    {
        if (! $t->isHandledBy($by)) {
            throw ValidationException::withMessages(['transport' => 'Задача поручена не вам']);
        }
        if (! in_array($t->state, [TransportState::Assigned, TransportState::Agreed], true)) {
            throw ValidationException::withMessages(['transport' => 'Вывоз '.mb_strtolower($t->state->label())]);
        }
        if ($reason = $t->pickupBlocker($by)) {
            throw ValidationException::withMessages(['transport' => $reason]);
        }
        // Перевод между парковками: куда — решено при отправке, ключи считает парковка.
        if (! $t->isPickup()) {
            $t->update(['state' => TransportState::Moving, 'picked_at' => now()]);
            TransportMoved::dispatch($t, $by, 'picked');

            return $t;
        }
        $to = Point::from($data['destination']);
        $address = trim((string) ($data['to_address'] ?? '')) ?: null;
        match (true) {
            $to === Point::Keeper && ! $t->saleOffer()?->keeper() => throw ValidationException::withMessages(['destination' => 'У машины нет менеджера с гаражом']),
            $to === Point::Yard && empty($data['to_yard_id']) => throw ValidationException::withMessages(['to_yard_id' => 'Какая парковка']),
            $to === Point::Other && ! $address => throw ValidationException::withMessages(['to_address' => 'Адрес']),
            // Админ отмечает факт за исполнителя и без кадров (забрали, а фото пришлют потом).
            ! $by->isAdmin() && $t->photos('pickup')->isEmpty() => throw ValidationException::withMessages(['photos' => 'Добавьте фото приёма']),
            default => null,
        };

        // Менеджер везёт к себе сам: забрал — значит уже у него, «Привёз» отдельно не нужен.
        $home = $to === Point::Keeper && $t->executor_id !== null && $t->executor_id === $t->saleOffer()?->keeper()?->id;
        DB::transaction(function () use ($t, $data, $by, $to, $address, $home) {
            $t->update([
                'state' => $home ? TransportState::Delivered : TransportState::Moving, 'picked_at' => now(), 'delivered_at' => $home ? now() : null,
                'destination' => $to, 'to_yard_id' => $to === Point::Yard ? (int) $data['to_yard_id'] : null, 'to_address' => $address,
                'pickup_note' => trim((string) ($data['pickup_note'] ?? '')) ?: null,
            ]);
            // Ключи — факт машины (одна запись на все приложения).
            $keys = (int) $data['keys'];
            $t->car?->update(['keys_count' => $keys, 'has_keys' => $keys > 0]);
            if ($offer = $t->saleOffer()) {
                ($this->place)($offer, $home ? CarPlace::Keeper : CarPlace::Moving, $by);
            }
        });
        TransportMoved::dispatch($t, $by, 'picked');
        if ($home) {
            TransportMoved::dispatch($t, $by, 'delivered');
        }

        return $t;
    }
}
