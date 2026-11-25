<?php

namespace App\Mail\Jobs;

use App\Notifications\OfferLetterNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Цепочку «Из писем» завели, убрали в архив или закрыли: её «Новое из писем» удаляется у всех — сообщения в Telegram
 * (по `telegram_messages.subject`) и строки ленты. Иначе второй модератор пошёл бы заводить уже заведённое.
 */
final class RetractCandidateNotices implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** Сбой Telegram — повтор (`Telegram\Retract`). @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200];
    }

    public function __construct(public int $candidateId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        app(\App\Telegram\Retract::class)->subject(OfferLetterNotice::subjectOf($this->candidateId), '«Новое из писем»');
        DatabaseNotification::where('type', OfferLetterNotice::class)
            ->where('data->subject', '/offers/from-mail?candidate='.$this->candidateId)->delete();
    }
}
