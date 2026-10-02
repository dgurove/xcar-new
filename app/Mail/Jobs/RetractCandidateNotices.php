<?php

namespace App\Mail\Jobs;

use App\Notifications\OfferLetterNotice;
use App\Telegram\Bot;
use App\Telegram\ChatMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Цепочку «Из писем» завели, убрали в архив или закрыли: её «Новое из писем» удаляется у всех — сообщения в Telegram
 * (по `telegram_messages.subject`) и строки ленты. Иначе второй модератор пошёл бы заводить уже заведённое.
 */
final class RetractCandidateNotices implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $candidateId)
    {
        $this->afterCommit();
    }

    public function handle(Bot $bot): void
    {
        $sent = ChatMessage::where('subject', OfferLetterNotice::subjectOf($this->candidateId))->where('direction', ChatMessage::OUT)->whereNull('deleted_at')->get();
        foreach ($sent as $message) {
            try {
                $bot->delete((int) $message->chat_id, (int) $message->message_id);
            } catch (Throwable $e) {
                // Человек уже удалил сам или остановил бота — сообщения нет, и ладно.
                Log::info('Telegram: «Новое из писем» не удалилось', ['message' => $message->id, 'error' => $e->getMessage()]);
                $message->update(['deleted_at' => now()]);
            }
        }
        DatabaseNotification::where('type', OfferLetterNotice::class)
            ->where('data->subject', '/offers/from-mail?candidate='.$this->candidateId)->delete();
    }
}
