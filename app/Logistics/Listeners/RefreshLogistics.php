<?php

namespace App\Logistics\Listeners;

use App\Live\Publisher;
use App\Live\Topics;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Users\Role;
use App\Users\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Перевозка поменялась — у тех, кто её видит (логисты, админы, исполнитель, прежний исполнитель), список перевозок
 * перечитывается морфом и значки пересчитываются: без обновления руками и без опроса.
 */
final class RefreshLogistics
{
    public function __construct(private Publisher $publish) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            TransportRequested::class => 'refresh',
            TransportAssigned::class => 'refresh',
            TransportMoved::class => 'refresh',
        ];
    }

    public function refresh(TransportRequested|TransportAssigned|TransportMoved $e): void
    {
        $ids = User::withRole(Role::Logist, Role::Admin)->pluck('id')
            ->push($e->transport->executor_id, $e instanceof TransportAssigned ? $e->previous?->id : null)
            ->filter()->unique();
        $topics = $ids->map(fn (int $id) => Topics::user($id))->values()->all();
        // События идут изнутри транзакции действия — перечитывать страницу раньше записи бессмысленно.
        DB::afterCommit(function () use ($topics) {
            $this->publish->refresh($topics, ['/transports']);
            $this->publish->badges($topics);
        });
    }
}
