<?php

namespace App\Mail\Jobs;

use App\Mail\Events\MessageFailed;
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

    /**
     * Письмо берётся атомарно из «в очереди» — вторая задача того же письма (двойной «Повторить») его не шлёт ещё раз. Ушло
     * по SMTP — это `Sent` навсегда: ошибка после (копия в «Отправленные», ветка, слушатели `MessageSent`) письмо «не ушло» не
     * делает (было: падение слушателя ставило `Failed`, «Повторить» слал письмо второй раз).
     */
    public function handle(Sender $sender): void
    {
        $message = Message::with(['account', 'addresses', 'attachments', 'thread'])->find($this->messageId);
        if (! $message || ! Message::whereKey($message->id)->where('send_state', SendState::Queued->value)->update(['send_state' => SendState::Sending->value, 'updated_at' => now()])) {
            return;
        }
        try {
            $sender->send($message);
        } catch (Throwable $e) {
            if ($message->send_state === SendState::Sent) {
                Log::error('Почта: письмо ушло, после отправки — ошибка', ['message' => $message->id, 'error' => $e->getMessage()]);
            } else {
                Log::error('Почта: письмо не ушло', ['message' => $message->id, 'error' => $e->getMessage()]);
                $message->forceFill(['send_state' => SendState::Failed, 'send_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                MessageFailed::dispatch($message);

                return;
            }
        }
        MessageSent::dispatch($message);
    }

    /**
     * Письмо «отправляется» дольше задачи (180 с) — её убили посреди SMTP (выкладка): `Failed` с причиной, чтобы было что
     * повторить, а не вечное «отправляется». Ушло ли — неизвестно, это сказано словами. Зовёт `mail:sync`.
     *
     * @return int сколько писем
     */
    public static function failStuck(): int
    {
        $n = 0;
        foreach (Message::where('send_state', SendState::Sending->value)->where('updated_at', '<', now()->subMinutes(15))->get() as $message) {
            if (Message::whereKey($message->id)->where('send_state', SendState::Sending->value)->update(['send_state' => SendState::Failed->value, 'send_error' => 'Отправка прервалась — проверьте «Отправленные» и повторите, если письма там нет', 'updated_at' => now()])) {
                MessageFailed::dispatch($message->refresh());
                $n++;
            }
        }

        return $n;
    }
}
