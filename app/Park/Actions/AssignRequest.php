<?php

namespace App\Park\Actions;

use App\Park\Events\RequestAssigned;
use App\Park\EventType;
use App\Park\Request;
use App\Support\Nav;
use App\Users\User;

/** Исполнитель заявки: кто поедет, кто примет. Пусто — снять. */
final class AssignRequest
{
    public function __invoke(Request $request, ?User $assignee, User $by): Request
    {
        Nav::forgetStaffCounts();
        $request->update(['assignee_id' => $assignee?->id]);
        $request->vehicle->log(EventType::Assigned, $by, ['user' => $assignee?->shortName() ?? 'снят']);
        if ($assignee && ! $assignee->is($by)) {
            RequestAssigned::dispatch($request->vehicle, $request, $assignee);
        }

        return $request;
    }
}
