<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Поправить день приёма и выдачи (владелец 09.10.2026: «по письмам расставили, а фактические даты были другие» — раньше
 * только отменой приёма и приёмом заново). Меняется день, время дня остаётся. Вместе с датой ТС двигается и день
 * события приёма (выдачи) в ленте — по нему `Vehicle::yardTimeline` знает, где ТС стояла, и история не расходится с
 * датой. Хранение уже выставлено — нельзя: счёт посчитан от прежней даты, сначала его аннулируют.
 */
final class SetDates
{
    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $accepted, ?Carbon $released): void
    {
        DB::transaction(function () use ($vehicle, $by, $accepted, $released) {
            $vehicle = Vehicle::withoutGlobalScopes()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $accepted = $accepted && $vehicle->accepted_at ? $vehicle->accepted_at->copy()->setDateFrom($accepted) : null;
            $released = $released && $vehicle->released_at ? $vehicle->released_at->copy()->setDateFrom($released) : null;
            $changes = array_filter([
                'accepted_at' => $accepted && ! $accepted->isSameDay($vehicle->accepted_at) ? $accepted : null,
                'released_at' => $released && ! $released->isSameDay($vehicle->released_at) ? $released : null,
            ]);
            if (! $changes) {
                return;
            }
            $errors = [];
            foreach ($changes as $key => $at) {
                if ($at->isAfter(now()->endOfDay())) {
                    $errors[$key] = 'Дата ещё не наступила';
                }
            }
            if (($changes['accepted_at'] ?? $vehicle->accepted_at) && ($changes['released_at'] ?? $vehicle->released_at)
                && ($changes['released_at'] ?? $vehicle->released_at)->copy()->startOfDay()->lt(($changes['accepted_at'] ?? $vehicle->accepted_at)->copy()->startOfDay())) {
                $errors[isset($changes['released_at']) ? 'released_at' : 'accepted_at'] = 'Выдача раньше приёма';
            }
            // Приём не позже первой перестановки или погрузки: иначе лента (`yardTimeline`) поставит переезд раньше приёма, и
            // с новой даты ТС считалась бы на парковке приёма, хотя уже стоит на другой.
            if (isset($changes['accepted_at'])) {
                $firstMove = $vehicle->events()->reorder()->whereIn('type', [EventType::Moved, EventType::Departed])->get()
                    ->map(fn ($e) => Carbon::parse($e->payload['day'] ?? $e->created_at)->startOfDay())->min();
                if ($firstMove && $changes['accepted_at']->copy()->startOfDay()->gt($firstMove)) {
                    $errors['accepted_at'] = 'Не позже перестановки '.$firstMove->translatedFormat('j M');
                }
            }
            if ($vehicle->storage_billed_until) {
                $errors[array_key_first($changes)] = 'Хранение выставлено по '.$vehicle->storage_billed_until->translatedFormat('j M').', сначала аннулируйте счёт';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $before = ['accepted' => $vehicle->accepted_at?->toDateString(), 'released' => $vehicle->released_at?->toDateString()];
            $vehicle->update($changes);
            // День события — первого приёма и последней выдачи: по ним лента и `yardTimeline`.
            foreach (['accepted_at' => [EventType::Accepted, 'oldest'], 'released_at' => [EventType::Released, 'latest']] as $key => [$type, $order]) {
                if (isset($changes[$key]) && ($event = $vehicle->events()->reorder()->where('type', $type)->{$order}('id')->first())) {
                    $event->update(['payload' => ['day' => $changes[$key]->toDateString()] + ($event->payload ?? [])]);
                }
            }
            $vehicle->log(EventType::DatesChanged, $by, array_filter([
                'accepted' => isset($changes['accepted_at']) ? [$before['accepted'], $changes['accepted_at']->toDateString()] : null,
                'released' => isset($changes['released_at']) ? [$before['released'], $changes['released_at']->toDateString()] : null,
            ]));
        });
        Nav::forgetStaffCounts();
    }
}
