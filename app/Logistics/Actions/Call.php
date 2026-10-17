<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Созвон с владельцем — первое, что делает исполнитель (17.10.2026, владелец): «Созвонились» — дата вывоза, место и
 * детали; «Перенести» — то же окно; «Не дозвонился» копит попытки со временем (их видит админ).
 */
final class Call
{
    public static function rules(): array
    {
        return [
            'pickup_at' => ['required', 'date'],
            'pickup_place' => ['required', 'string', 'max:500'],
            'call_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function messages(): array
    {
        return ['pickup_at.required' => 'Когда вывоз', 'pickup_place.required' => 'Где забрать'];
    }

    /** «Созвонились» и «Перенести». */
    public function agree(Transport $t, array $data, User $by): Transport
    {
        $this->guard($t, $by);
        $at = Carbon::parse($data['pickup_at'], 'Europe/Moscow');
        if ($at->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['pickup_at' => 'Дата вывоза уже прошла']);
        }
        $t->update([
            'pickup_at' => $at, 'pickup_place' => trim($data['pickup_place']), 'call_note' => trim((string) ($data['call_note'] ?? '')) ?: null,
            'state' => TransportState::Agreed, 'agreed_at' => $t->agreed_at ?? now(),
        ]);
        TransportMoved::dispatch($t, $by, 'agreed');

        return $t;
    }

    /** «Не дозвонился». */
    public function missed(Transport $t, User $by): Transport
    {
        $this->guard($t, $by);
        $t->update(['call_attempts' => [...($t->call_attempts ?? []), ['at' => now()->toIso8601String(), 'by' => $by->id]]]);
        TransportMoved::dispatch($t, $by, 'missed');

        return $t;
    }

    private function guard(Transport $t, User $by): void
    {
        if (! $t->isHandledBy($by)) {
            throw ValidationException::withMessages(['transport' => 'Задача поручена не вам']);
        }
        if (! $t->isPickup() || ! in_array($t->state, [TransportState::Assigned, TransportState::Agreed], true)) {
            throw ValidationException::withMessages(['transport' => 'Вывоз '.mb_strtolower($t->state->label())]);
        }
    }
}
