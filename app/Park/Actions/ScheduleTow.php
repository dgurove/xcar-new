<?php

namespace App\Park\Actions;

use App\Park\Events\TowScheduled;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Эвакуация назначена: дата, откуда, куда, перевозчик, километры и цена. Повторный вызов — перенос. */
final class ScheduleTow
{
    public function __invoke(Request $request, User $by, array $data): Request
    {
        if ($request->type !== RequestType::Tow || ! $request->isOpen()) {
            throw ValidationException::withMessages(['state' => 'Заявка не на эвакуацию или уже закрыта']);
        }
        Nav::forgetStaffCounts();
        $request = DB::transaction(function () use ($request, $by, $data) {
            $request->update(array_filter([
                'planned_at' => $data['planned_at'] ?? null,
                'from_address' => $data['from_address'] ?? null,
                'yard_id' => $data['yard_id'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'distance_km' => $data['distance_km'] ?? null,
                'cost' => $data['cost'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ], fn ($v) => $v !== null && $v !== '') + ['state' => $request->state === RequestState::New ? RequestState::Scheduled : $request->state]);
            $request->vehicle->log(EventType::Scheduled, $by, array_filter([
                'at' => $request->planned_at?->translatedFormat('j M, H:i'), 'from' => $request->from_address, 'carrier' => $request->carrier,
            ]));

            return $request;
        });
        TowScheduled::dispatch($request->vehicle, $request, $by);

        return $request;
    }
}
