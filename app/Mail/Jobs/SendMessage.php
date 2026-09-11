<?php

namespace App\Mail\Jobs;

use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\Sender;
use App\Mail\SendState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
        // Долгое соединение: у штатного retry_after 90 с, письмо разбирается дольше.
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(Sender $sender): void
    {
        $message = Message::with(['account', 'addresses', 'attachments', 'thread'])->find($this->messageId);
        if (! $message || $message->send_state === SendState::Sent) {
            return;
        }
        try {
            $sender->send($message);
            MessageSent::dispatch($message);
        } catch (Throwable $e) {
            Log::error('Почта: письмо не ушло', ['message' => $message->id, 'error' => $e->getMessage()]);
            $message->forceFill(['send_state' => SendState::Failed, 'send_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
        }
    }
}
