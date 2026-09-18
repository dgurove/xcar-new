<?php

namespace App\Park\Actions;

use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Осмотр закрывается отметкой; отмена — любой открытой заявки, с причиной. Исполнитель не перетирается: кто закрыл — `done_by`. */
final class CloseRequest
{
    public function __invoke(Request $request, User $by, bool $done, ?string $note = null): Request
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($request, $by, $done, $note) {
            $request->update([
                'state' => $done ? RequestState::Done : RequestState::Cancelled,
                'done_at' => now(), 'done_by' => $by->id,
                'note' => $done ? ($note ?: $request->note) : $request->note,
                'cancel_reason' => $done ? null : ($note ?: null),
            ]);
            if ($done && $request->type === RequestType::Inspection) {
                $request->vehicle->log(EventType::Inspected, $by, array_filter(['note' => $note]));
            }

            return $request;
        });
    }
}
