<?php

namespace App\Park\Jobs;

use App\Park\Actions\PurgeLetters;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ТС выдана — файлы из писем стираются, письма замораживаются (`PurgeLetters`) фоном, а не в запросе выдачи (30.10.2026:
 * сотрудник парковки — «выдаёшь, а машина не сразу уходит из списка»; по 80 файлам выдача шла 2 с, полторы тысячи
 * запросов). Выдачу успели отменить — ничего не трогаем: `UndoRelease` вернёт письма сам.
 */
final class PurgeReleasedLetters implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Замок не вечный: упавшая постановка не запирает машину навсегда. Выдали, вернули, выдали — одна задача в очереди. */
    public int $uniqueFor = 3600;

    public function __construct(public int $vehicleId)
    {
        $this->onConnection('database-long')->onQueue('files')->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->vehicleId;
    }

    public function handle(PurgeLetters $purge): void
    {
        $vehicle = Vehicle::find($this->vehicleId);
        if ($vehicle?->state === VehicleState::Released) {
            $purge($vehicle);
        }
    }
}
