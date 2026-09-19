<?php

namespace App\Mail\Actions;

use App\Mail\Direction;
use App\Mail\Jobs\PushFlag;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Support\Nav;

final class MarkThreadRead
{
    public function __construct(private Threads $threads) {}

    public function __invoke(Thread $thread, bool $read = true): void
    {
        $messages = $thread->messages()->where('direction', Direction::In)->where('is_seen', ! $read)->get();
        if ($messages->isEmpty()) {
            return;
        }
        foreach ($messages as $message) {
            $message->forceFill(['is_seen' => $read])->save();
            PushFlag::dispatch($message->id, '\\Seen', $read);
        }
        $this->threads->refresh($thread);
        Nav::forgetStaffCounts();
    }
}
