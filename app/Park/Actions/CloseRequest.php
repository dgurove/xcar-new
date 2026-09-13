<?php

namespace App\Park\Actions;

use App\Support\Nav;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Осмотр и эвакуация закрываются отметкой; отмена — любой заявки. */
final class CloseRequest
{
    public function __invoke(Request $request, User $by, bool $done, ?string $note = null): Request
    {
        Nav::forgetStaffCounts();
        return DB::transaction(function () use ($request, $by, $done, $note) {
            $request->update(['state' => $done ? RequestState::Done : RequestState::Cancelled, 'done_at' => now(), 'assignee_id' => $by->id, 'note' => $note ?: $request->note]);
            if ($done && in_array($request->type, [RequestType::Inspection, RequestType::Tow], true)) {
                $request->vehicle->log($request->type === RequestType::Inspection ? EventType::Inspected : EventType::Towed, $by, array_filter(['note' => $note]));
            }

            return $request;
        });
    }
}
