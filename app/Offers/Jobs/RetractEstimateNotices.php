<?php

namespace App\Offers\Jobs;

use App\Notifications\EstimateAskedNotice;
use App\Notifications\EstimateDueNotice;
use App\Notifications\EstimateNotice;
use App\Notifications\EstimateRejectedNotice;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Просьбу оценить закрыли (цена поставлена, черновик ушёл, сняли): «Оцените» удаляется у менеджера — сообщения в Telegram
 * и строки ленты, иначе он пошёл бы оценивать то, что уже не нужно.
 */
final class RetractEstimateNotices implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $estimateIds  @param  list<int>  $userIds */
    public int $tries = 6;

    /** Сбой Telegram — повтор (`Telegram\Retract`). @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200];
    }

    public function __construct(public array $estimateIds, public array $userIds, public string $subject)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $subjects = array_map(fn (int $id) => EstimateNotice::subjectOf($id), $this->estimateIds);
        app(\App\Telegram\Retract::class)->subject($subjects, '«Оцените»');
        DatabaseNotification::whereIn('type', [EstimateAskedNotice::class, EstimateDueNotice::class, EstimateRejectedNotice::class])
            ->where('notifiable_type', User::class)->whereIn('notifiable_id', $this->userIds)
            ->where('data->subject', $this->subject)->delete();
    }
}
