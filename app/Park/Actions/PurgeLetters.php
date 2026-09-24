<?php

namespace App\Park\Actions;

use App\Mail\Actions\FreezeMessages;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Vehicle;

/**
 * ТС выдана: то, что пришло из писем, больше не хранится — кадры и документы из писем в галерее и бумагах ТС
 * стираются, письма её веток замораживаются (`FreezeMessages`), кадр кандидата снимается. Снятое в приложении
 * остаётся: в ящике его нет. При возврате выдачи `ImportThreadFiles` принесёт стёртое из ящика снова.
 */
final class PurgeLetters
{
    public function __construct(private FreezeMessages $freeze) {}

    public function __invoke(Vehicle $vehicle): int
    {
        foreach ($vehicle->media()->where('collection_name', 'photos')->where('custom_properties->source', 'mail')->get() as $media) {
            $media->delete();
        }
        foreach ($vehicle->media()->where('collection_name', 'papers')->where('custom_properties->source', 'mail')->get() as $media) {
            $media->delete();
        }
        $threads = Thread::where('vehicle_id', $vehicle->id)->park()->pluck('id');

        return $threads->isEmpty() ? 0 : $this->freeze->freeze(Message::whereIn('thread_id', $threads)->pluck('id')->all());
    }

    /** Выдачу вернули: письма читаются заново, файлы и кадры едут в дело из ящика. */
    public function undo(Vehicle $vehicle): void
    {
        $threads = Thread::where('vehicle_id', $vehicle->id)->park()->pluck('id');
        if ($threads->isEmpty()) {
            return;
        }
        $this->freeze->thaw(Message::whereIn('thread_id', $threads)->whereNotNull('frozen_at')->pluck('id')->all());
        foreach ($threads as $id) {
            ImportThreadFiles::dispatch($id);
        }
    }
}
