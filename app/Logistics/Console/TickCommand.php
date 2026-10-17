<?php

namespace App\Logistics\Console;

use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Часы логистики: дата вывоза прошла, «Забрал» нет — админам и исполнителю, раз на задачу (перенесли — снова). */
final class TickCommand extends Command
{
    protected $signature = 'logistics:tick';

    protected $description = 'Вывозы, не забранные в срок';

    public function handle(): int
    {
        Transport::where('state', TransportState::Agreed)->where('pickup_at', '<', now()->subHours(2))
            ->where(fn ($q) => $q->whereNull('late_noticed_at')->orWhereColumn('late_noticed_at', '<', 'pickup_at'))
            ->with(['executorUser', 'car'])->get()
            ->each(function (Transport $t) {
                Notification::send(User::withRole(Role::Admin)->get()->push($t->executorUser)->filter()->unique('id'), TransportNotice::late($t));
                $t->update(['late_noticed_at' => now()]);
            });

        return self::SUCCESS;
    }
}
