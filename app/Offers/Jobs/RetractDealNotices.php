<?php

namespace App\Offers\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\Notice;
use App\Offers\Deal;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Сделку отменили — её уведомления у покупателя стираются: строки ленты с темой `/deals/{id}` и сообщения основного бота
 * (`Notice::dealSubject`). 17.10.2026: админ отменил сделку Макарова и через минуту принял снова — у менеджера осталось
 * «принято», ведущее в «Отменена». Отмена, как и отказ, — тихо.
 */
final class RetractDealNotices implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** Сбой Telegram — повтор (`Telegram\Retract`). @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200];
    }

    public function __construct(public int $dealId)
    {
        $this->afterCommit();
    }

    public function handle(Publisher $publish): void
    {
        $deal = Deal::find($this->dealId);
        if (! $deal?->buyer_id) {
            return;
        }
        $deleted = DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $deal->buyer_id)
            ->where('data->subject', "/deals/{$deal->id}")->delete();
        if ($deleted && ($user = User::find($deal->buyer_id))) {
            $publish->badges(Topics::user($user));
        }
        // Telegram — последним: сбой бросает задание на повтор (`Telegram\Retract`).
        app(\App\Telegram\Retract::class)->subject(Notice::dealSubject($deal->id), 'уведомление о сделке');
    }
}
