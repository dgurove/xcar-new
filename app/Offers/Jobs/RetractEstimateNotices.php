<?php

namespace App\Offers\Jobs;

use App\Notifications\EstimateAskedNotice;
use App\Notifications\EstimateDueNotice;
use App\Notifications\EstimateNotice;
use App\Notifications\EstimateRejectedNotice;
use App\Telegram\Bot;
use App\Telegram\ChatMessage;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Просьбу оценить закрыли (цена поставлена, черновик ушёл, сняли): «Оцените» удаляется у менеджера — сообщения в Telegram
 * и строки ленты, иначе он пошёл бы оценивать то, что уже не нужно.
 */
final class RetractEstimateNotices implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $estimateIds  @param  list<int>  $userIds */
    public function __construct(public array $estimateIds, public array $userIds, public string $subject)
    {
        $this->afterCommit();
    }

    public function handle(Bot $bot): void
    {
        $subjects = array_map(fn (int $id) => EstimateNotice::subjectOf($id), $this->estimateIds);
        foreach (ChatMessage::whereIn('subject', $subjects)->where('direction', ChatMessage::OUT)->whereNull('deleted_at')->get() as $message) {
            try {
                $bot->delete((int) $message->chat_id, (int) $message->message_id);
            } catch (Throwable $e) {
                Log::info('Telegram: «Оцените» не удалилось', ['message' => $message->id, 'error' => $e->getMessage()]);
                $message->update(['deleted_at' => now()]);
            }
        }
        DatabaseNotification::whereIn('type', [EstimateAskedNotice::class, EstimateDueNotice::class, EstimateRejectedNotice::class])
            ->where('notifiable_type', User::class)->whereIn('notifiable_id', $this->userIds)
            ->where('data->subject', $this->subject)->delete();
    }
}
