<?php

namespace App\Park\Console;

use App\Park\Idle;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\ParkDigest;
use Illuminate\Console\Command;

/** Утренняя сводка стоянки владельцу в Telegram; в выходные и пустая — не шлётся. */
class ParkDigestCommand extends Command
{
    protected $signature = 'park:digest';

    protected $description = 'Сводка стоянки владельцу: просрочено, забрать, принять, выдать, стоят долго';

    public function handle(): int
    {
        $open = Request::whereIn('state', RequestState::open())->get();
        $today = fn (RequestType $t) => $open->filter(fn ($r) => $r->type === $t && ! $r->isOverdue() && (! $r->planned_at || $r->planned_at->isToday()))->count();
        $counts = [
            'Просрочено' => $open->filter->isOverdue()->count(),
            'Связаться' => $open->filter(fn ($r) => $r->isTow() && $r->state === RequestState::New && ! $r->planned_at)->count(),
            'Забрать' => $open->filter(fn ($r) => $r->isTow() && $r->planned_at?->isToday())->count(),
            'Принять' => $today(RequestType::Intake) + Vehicle::where('state', VehicleState::InTransit)->count(),
            'Выдать' => $today(RequestType::Release),
            'На стоянке' => Vehicle::where('state', VehicleState::Stored)->count(),
            'Стоят дольше '.Idle::WARN.' дн' => Vehicle::where('state', VehicleState::Stored)->where('accepted_at', '<', now()->subDays(Idle::WARN))->count(),
        ];
        if (array_sum(array_slice($counts, 0, 5)) === 0) {
            return self::SUCCESS;
        }
        NotifyOwner::dispatch(new ParkDigest($counts));
        $this->info(json_encode($counts, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
