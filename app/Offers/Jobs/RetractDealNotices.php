<?php

namespace App\Offers\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\Notice;
use App\Offers\Deal;
use App\Telegram\Bot;
use App\Telegram\ChatMessage;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Сделку отменили — её уведомления у покупателя стираются: строки ленты с темой `/deals/{id}` и сообщения основного бота
 * (`Notice::dealSubject`). 17.10.2026: админ отменил сделку Макарова и через минуту принял снова — у менеджера осталось
 * «принято», ведущее в «Отменена». Отмена, как и отказ, — тихо.
 */
final class RetractDealNotices implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $dealId)
    {
        $this->afterCommit();
    }

    public function handle(Bot $bot, Publisher $publish): void
    {
        $deal = Deal::find($this->dealId);
        if (! $deal?->buyer_id) {
            return;
        }
        foreach (ChatMessage::where('subject', Notice::dealSubject($deal->id))->where('direction', ChatMessage::OUT)->whereNull('deleted_at')->get() as $message) {
            try {
                $bot->delete((int) $message->chat_id, (int) $message->message_id);
            } catch (Throwable $e) {
                Log::info('Telegram: уведомление о сделке не удалилось', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
            $message->update(['deleted_at' => now()]);
        }
        $deleted = DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $deal->buyer_id)
            ->where('data->subject', "/deals/{$deal->id}")->delete();
        if ($deleted && ($user = User::find($deal->buyer_id))) {
            $publish->badges(Topics::user($user));
        }
    }
}
