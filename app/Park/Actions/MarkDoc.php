<?php

namespace App\Park\Actions;

use App\Park\Doc;
use App\Park\DocState;
use App\Park\EventType;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Carbon;

/** Бумага вендору: отправлена / получена / ещё нет — с датой, сканом и письмом. Без человека — автозаведение по письмам. */
final class MarkDoc
{
    public function __invoke(Doc $doc, ?User $by, DocState $state, ?Carbon $at = null, ?int $mediaId = null, ?int $threadId = null, ?string $note = null): Doc
    {
        Nav::forgetStaffCounts();
        $doc->update([
            'state' => $state,
            'at' => $state === DocState::Pending ? null : ($at ?? now()),
            'user_id' => $state === DocState::Pending ? null : $by?->id,
            'media_id' => $mediaId ?? $doc->media_id,
            'thread_id' => $threadId ?? $doc->thread_id,
            'note' => $note ?? $doc->note,
        ]);
        $doc->vehicle->log($state === DocState::Pending ? EventType::DocBack : EventType::DocSent, $by, ['doc' => $doc->kind->label(), 'direction' => $state === DocState::Received ? 'in' : 'out']);

        return $doc;
    }
}
