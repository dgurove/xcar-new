<?php

namespace App\Mail\Jobs;

use App\Mail\Imap;
use App\Mail\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Флаг (прочитано, отмечено) — на сервер, чтобы веб-почта показывала то же. */
final class PushFlag implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public string $flag, public bool $enabled)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(): void
    {
        $message = Message::with(['account', 'folder'])->find($this->messageId);
        if (! $message?->existsOnServer()) {
            return;
        }
        $imap = new Imap($message->account, 15);
        try {
            $imap->setFlag($message->folder->path, $message->imap_uid, $this->flag, $this->enabled);
        } catch (Throwable $e) {
            Log::warning('Почта: флаг не ушёл на сервер', ['message' => $message->id, 'flag' => $this->flag, 'error' => $e->getMessage()]);
        } finally {
            $imap->disconnect();
        }
    }
}
