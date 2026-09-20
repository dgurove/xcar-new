<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Осмотр закрывается отметкой; отмена — любой открытой заявки, с причиной. Исполнитель не перетирается: кто закрыл — `done_by`.
 * Отмена эвакуации, которая уже выехала, возвращает ТС из «в пути»: на прежнюю площадку (перегон) или в ожидание.
 */
final class CloseRequest
{
    public function __invoke(Request $request, User $by, bool $done, ?string $note = null): Request
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($request, $by, $done, $note) {
            if (! $request->isOpen()) {
                throw ValidationException::withMessages(['state' => 'Заявка уже '.mb_strtolower($request->state->label())]);
            }
            if (! $done && $request->isTow() && $request->state === RequestState::InProgress && $request->vehicle->state === VehicleState::InTransit) {
                $vehicle = $request->vehicle;
                // Откуда уехала — в записи погрузки; у старых записей — последняя площадка из ленты.
                $departed = $vehicle->events()->reorder()->where('type', EventType::Departed)->latest('created_at')->latest('id')->first();
                $backId = $departed?->payload['from_id'] ?? collect($vehicle->yardTimeline())->last(fn ($t) => $t['yard_id'] !== null)['yard_id'] ?? null;
                $vehicle->update($vehicle->accepted_at && $backId
                    ? ['state' => VehicleState::Stored, 'yard_id' => $backId, 'spot' => $departed?->payload['from_spot'] ?? null, 'transit_started_at' => null]
                    : ['state' => VehicleState::Expected, 'transit_started_at' => null]);
                $vehicle->log(EventType::Note, $by, ['text' => 'Эвакуация отменена'.($note ? ': '.$note : '')]);
            }
            $request->update([
                'state' => $done ? RequestState::Done : RequestState::Cancelled,
                'done_at' => now(), 'done_by' => $by->id,
                'note' => $done ? ($note ?: $request->note) : $request->note,
                'cancel_reason' => $done ? null : ($note ?: null),
            ]);
            if ($done && $request->type === RequestType::Inspection) {
                $request->vehicle->log(EventType::Inspected, $by, array_filter(['note' => $note]));
            }

            return $request;
        });
    }
}
