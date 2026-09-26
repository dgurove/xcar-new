<?php

namespace App\Park\Actions;

use App\Park\Events\VehicleReleased;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Выдана задним числом — по письмам (`RegisterFromLetters`, `ReleaseByLetters`): как `Release`, но без осмотра,
 * проверки долга и QR — выдача уже случилась, счета за прошлое выставит закрытие месяца. Живой пропуск гасится.
 */
final class ReleasePast
{
    public function __construct(private PurgeLetters $purge) {}

    public function __invoke(Vehicle $vehicle, ?User $by, Carbon $at, ?string $note, array $mark): void
    {
        $at = $vehicle->accepted_at && $at->lt($vehicle->accepted_at) ? $vehicle->accepted_at->copy() : $at;
        DB::transaction(function () use ($vehicle, $by, $at, $note, $mark) {
            $vehicle->update(['state' => VehicleState::Released, 'released_at' => $at, 'spot' => null]);
            $vehicle->log(EventType::Released, $by, array_filter(['note' => $note, 'day' => $at->toDateString()]) + $mark);
            Request::where('vehicle_id', $vehicle->id)->whereIn('state', RequestState::open())
                ->update(['state' => RequestState::Done, 'done_at' => now(), 'done_by' => $by?->id]);
            $vehicle->revokePass('выдана по письмам');
        });
        VehicleReleased::dispatch($vehicle, null, $by);
        ($this->purge)($vehicle);
    }
}
