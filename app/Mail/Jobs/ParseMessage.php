<?php

namespace App\Mail\Jobs;

use App\Mail\Imap;
use App\Mail\Ingest;
use App\Mail\Message;
use App\Mail\ParseState;
use App\Mail\Receiver;
use App\Mail\Threads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пересборка письма с сервера: заголовки, текст и опись вложений берутся
 * заново по UID. Нужна, когда приём не дошёл до конца или изменился разбор.
 * Письма, которого в ящике уже нет, остаётся как было.
 */
final class ParseMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
        // Долгое соединение: у штатного retry_after 90 с, письмо разбирается дольше.
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(Receiver $receiver, Ingest $ingest, Threads $threads): void
    {
        $message = Message::with(['account', 'folder'])->find($this->messageId);
        if (! $message) {
            return;
        }
        if (! $message->existsOnServer()) {
            $this->fail($message, $threads, 'Письма нет в ящике, пересобрать нечего');

            return;
        }
        $imap = new Imap($message->account);
        try {
            $row = $imap->structures($message->folder->path, [$message->imap_uid])[$message->imap_uid] ?? null;
            if (! $row) {
                $this->fail($message, $threads, 'Письмо удалено из ящика');

                return;
            }
            $parsed = $receiver->receive($imap, $message->folder->path, $message->imap_uid, $row['structure']);
        } catch (Throwable $e) {
            Log::error('Почта: письмо не разобралось', ['message' => $message->id, 'error' => $e->getMessage()]);
            $this->fail($message, $threads, $e->getMessage());

            return;
        } finally {
            $imap->disconnect();
        }
        $ingest->apply($message, $parsed);
    }

    private function fail(Message $message, Threads $threads, string $error): void
    {
        $message->forceFill(['parse_state' => ParseState::Failed, 'parse_error' => mb_substr($error, 0, 2000)])->save();
        if ($message->thread_id) {
            return;
        }
        $thread = $threads->resolve($message->account, [
            'message_id' => $message->message_id, 'in_reply_to' => $message->in_reply_to, 'references' => $message->references_header,
            'subject' => $message->subject, 'subject_normalized' => $message->subject_normalized, 'date' => $message->date_at, 'addresses' => [],
        ]);
        $message->forceFill(['thread_id' => $thread->id])->save();
        $threads->refresh($thread);
    }
}
