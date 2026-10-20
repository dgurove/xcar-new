<?php

namespace App\Logistics\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Logistics\Route;
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
    public function __construct(private SetCarPlace $place, private Deliver $deliver, private VoidInvoice $void) {}

    public static function rules(): array
    {
        return [
            'picked_at' => ['required', 'date', 'before_or_equal:now'],
            'keys' => ['nullable', 'integer', 'min:0', 'max:9'],
            'destination' => ['nullable', 'string', 'max:20'],
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
        // Маршрут через стоянки (20.10.2026): «уже вывезли» — машина уже в точке; отрезки впереди не нужны, этот кончается
        // там, куда привезли.
        $ahead = Transport::where('route_id', $t->route_id)->where('seq', '>', $t->seq)->where('state', TransportState::Planned)->get();
        $choose = $t->isPickup() || $ahead->isNotEmpty();
        // Перевод парковки: куда — решено при отправке; у вывоза — точка из формы, по умолчанию менеджеру с гаражом или к нам.
        [$chosen, $yard] = Point::read($data);
        $yard ??= $t->to_yard_id;
        $to = $choose ? ($chosen ?? ($t->saleOffer()?->keeper() ? Point::Keeper : ($ahead->last()?->destination ?? $t->destination))) : $t->destination;
        $address = trim((string) ($data['to_address'] ?? '')) ?: null;
        if ($choose) {
            match (true) {
                $to === Point::Keeper && ! $t->saleOffer()?->keeper() => throw ValidationException::withMessages(['destination' => 'У машины нет менеджера с гаражом или сделкой']),
                $to === null => throw ValidationException::withMessages(['destination' => 'Куда привезли']),
                $to === Point::Yard && ! $yard => throw ValidationException::withMessages(['destination' => 'Какая парковка']),
                $to === Point::Other && ! $address => throw ValidationException::withMessages(['to_address' => 'Адрес']),
                default => null,
            };
        }

        DB::transaction(function () use ($t, $data, $by, $at, $to, $address, $picked, $yard, $ahead, $choose) {
            // Отрезки впереди не начинались — их просто нет (и неоплаченного долга логисту за них).
            $ahead->each(function (Transport $leg) use ($by) {
                if ($leg->invoice && $leg->invoice->state === InvoiceState::Issued && (float) $leg->invoice->paid === 0.0) {
                    ($this->void)($leg->invoice, $by, 'Уже вывезли');
                }
                $leg->events()->delete();
                $leg->delete();
            });
            $t->update([
                'state' => TransportState::Delivered,
                'picked_at' => $picked ? $t->picked_at : $at,
                'delivered_at' => $picked && $t->picked_at?->gt($at) ? $t->picked_at : $at,
                ...($choose ? [
                    'destination' => $to, 'to_yard_id' => $to === Point::Yard ? $yard : null, 'depot_id' => $to === Point::Depot ? $t->depot_id : null,
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
            Route::continueAt($t->fresh(), $by);
            Route::sync($t->route_id, $by);
        });
        // «Вывезена» — без уведомлений: о деле говорит «привёз».
        if (! $picked) {
            TransportMoved::dispatch($t, $by, 'picked', true);
        }
        TransportMoved::dispatch($t, $by, 'delivered', $batch);

        return $t;
    }
}
