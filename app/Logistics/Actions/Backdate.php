<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Уже вывезли» (18.10.2026, владелец: «подумай, как добавить вывоз задним числом» — на проде задачи висели «Без
 * исполнителя», а машины давно забрали): ведущий логистику закрывает открытую задачу как привезённую — когда и куда,
 * ключи по желанию. Дальше всё как после «Привёз»: место машины по точке, ТС парковки — на парковку назначения, шаг
 * продажи «Менеджер забирает автомобиль» проходит сам (`SyncHandover`, привезли менеджеру сделки), гаражная машина
 * начинает подготовку (`StartPrepOnArrival`). `batch` — пачкой: по задаче уведомлений нет, сводку шлёт контроллер.
 */
final class Backdate
{
    public function __construct(private SetCarPlace $place, private Deliver $deliver) {}

    public static function rules(): array
    {
        return [
            'picked_at' => ['required', 'date', 'before_or_equal:now'],
            'keys' => ['nullable', 'integer', 'min:0', 'max:9'],
            'destination' => ['nullable', Rule::enum(Point::class)],
            'to_yard_id' => ['nullable', 'integer', Rule::exists('park_yards', 'id')],
            'to_address' => ['nullable', 'string', 'max:500'],
            'pickup_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return ['picked_at.required' => 'Когда вывезли', 'picked_at.before_or_equal' => 'Не позже, чем сейчас'];
    }

    public function __invoke(Transport $t, array $data, User $by, bool $batch = false): Transport
    {
        if (! $by->leadsLogistics()) {
            throw ValidationException::withMessages(['transport' => 'Отмечают админ и модераторы']);
        }
        if (! $t->isOpen()) {
            throw ValidationException::withMessages(['transport' => 'Вывоз '.mb_strtolower($t->state->label())]);
        }
        $at = Carbon::parse($data['picked_at']);
        $picked = $t->state->picked();
        // Перевод парковки: куда — решено при отправке; у вывоза — точка из формы, по умолчанию менеджеру с гаражом или к нам.
        $to = $t->isPickup() ? (isset($data['destination']) && $data['destination'] !== '' ? Point::from($data['destination'])
            : ($t->saleOffer()?->keeper() ? Point::Keeper : Point::Ours)) : $t->destination;
        $address = trim((string) ($data['to_address'] ?? '')) ?: null;
        if ($t->isPickup()) {
            match (true) {
                $to === Point::Keeper && ! $t->saleOffer()?->keeper() => throw ValidationException::withMessages(['destination' => 'У машины нет менеджера с гаражом или сделкой']),
                $to === Point::Yard && empty($data['to_yard_id']) => throw ValidationException::withMessages(['to_yard_id' => 'Какая парковка']),
                $to === Point::Other && ! $address => throw ValidationException::withMessages(['to_address' => 'Адрес']),
                default => null,
            };
        }

        DB::transaction(function () use ($t, $data, $by, $at, $to, $address, $picked) {
            $t->update([
                'state' => TransportState::Delivered,
                'picked_at' => $picked ? $t->picked_at : $at,
                'delivered_at' => $picked && $t->picked_at?->gt($at) ? $t->picked_at : $at,
                ...($t->isPickup() ? [
                    'destination' => $to, 'to_yard_id' => $to === Point::Yard ? (int) $data['to_yard_id'] : null,
                    'to_address' => $to === Point::Other ? $address : $t->to_address,
                    'pickup_note' => trim((string) ($data['pickup_note'] ?? '')) ?: $t->pickup_note,
                ] : []),
            ]);
            if (isset($data['keys']) && $data['keys'] !== '' && $data['keys'] !== null) {
                $keys = (int) $data['keys'];
                $t->car?->update(['keys_count' => $keys, 'has_keys' => $keys > 0]);
            }
            if ($offer = $t->saleOffer()) {
                ($this->place)($offer, ($to ?? Point::Ours)->place(), $by);
            }
            $this->deliver->toYard($t->unsetRelation('toYard'), $by);
        });
        // «Вывезена» — без уведомлений: о деле говорит «привёз».
        if (! $picked) {
            TransportMoved::dispatch($t, $by, 'picked', true);
        }
        TransportMoved::dispatch($t, $by, 'delivered', $batch);

        return $t;
    }
}
