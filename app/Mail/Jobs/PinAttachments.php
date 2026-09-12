<?php

namespace App\Mail\Jobs;

use App\Mail\Actions\PinThread;
use App\Mail\Thread;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Закрепить файлы ветки в фоне: письма страховых — по 30 фотографий, из ящика это минуты. */
final class PinAttachments implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 2;

    public function __construct(public int $threadId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function uniqueId(): string
    {
        return (string) $this->threadId;
    }

    public function handle(PinThread $pin): void
    {
        $thread = Thread::find($this->threadId);
        if ($thread?->isLinked()) {
            $pin($thread);
        }
    }
}
