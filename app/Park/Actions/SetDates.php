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
 * Поправить дату и время приёма и выдачи (владелец 09.10.2026: «по письмам расставили, а фактические даты были другие»;
 * 10.10.2026: сама дата — календарь со временем, только администратору парковки). Вместе с датой ТС двигается и день
 * события приёма (выдачи) в ленте — по нему `Vehicle::yardTimeline` знает, где ТС стояла, и история не расходится с
 * датой. Хранение уже выставлено — нельзя: счёт посчитан от прежней даты, сначала его аннулируют.
 */
final class SetDates
{
    public function __invoke(Vehicle $vehicle, User $by, ?Carbon $accepted, ?Carbon $released): void
    {
        DB::transaction(function () use ($vehicle, $by, $accepted, $released) {
            $vehicle = Vehicle::withoutGlobalScopes()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $same = fn (?Carbon $new, ?Carbon $old) => ! $new || ! $old || $new->format('Y-m-d H:i') === $old->format('Y-m-d H:i');
            $changes = array_filter([
                'accepted_at' => $same($accepted, $vehicle->accepted_at) ? null : $accepted->copy()->second(0),
                'released_at' => $same($released, $vehicle->released_at) ? null : $released->copy()->second(0),
            ]);
            if (! $changes) {
                return;
            }
            $errors = [];
            foreach ($changes as $key => $at) {
                if ($at->isAfter(now())) {
                    $errors[$key] = 'Это время ещё не наступило';
                }
            }
            $acceptedAt = $changes['accepted_at'] ?? $vehicle->accepted_at;
            $releasedAt = $changes['released_at'] ?? $vehicle->released_at;
            if ($acceptedAt && $releasedAt && $releasedAt->lt($acceptedAt)) {
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

            $before = ['accepted' => $vehicle->accepted_at?->format('Y-m-d H:i'), 'released' => $vehicle->released_at?->format('Y-m-d H:i')];
            $vehicle->update($changes);
            // День события — первого приёма и последней выдачи: по ним лента и `yardTimeline`.
            foreach (['accepted_at' => [EventType::Accepted, 'oldest'], 'released_at' => [EventType::Released, 'latest']] as $key => [$type, $order]) {
                if (isset($changes[$key]) && ($event = $vehicle->events()->reorder()->where('type', $type)->{$order}('id')->first())) {
                    $event->update(['payload' => ['day' => $changes[$key]->toDateString()] + ($event->payload ?? [])]);
                }
            }
            $vehicle->log(EventType::DatesChanged, $by, array_filter([
                'accepted' => isset($changes['accepted_at']) ? [$before['accepted'], $changes['accepted_at']->format('Y-m-d H:i')] : null,
                'released' => isset($changes['released_at']) ? [$before['released'], $changes['released_at']->format('Y-m-d H:i')] : null,
            ]));
        });
        Nav::forgetStaffCounts();
    }
}
