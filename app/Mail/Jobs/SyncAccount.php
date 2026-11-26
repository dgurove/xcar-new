<?php

namespace App\Mail\Jobs;

use App\Mail\Account;
use App\Mail\Sync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Замок «синк ящика уже стоит» — не дольше самой задачи (26.11.2026; без срока он жил, пока задачу не разберут: убитая
     * выкладкой держала его до `retry_after` 35 мин, и IDLE молча не ставил синк). Убитую задачу возвращает `queue:release-orphans`.
     */
    public int $uniqueFor = 660;

    public function __construct(public int $accountId)
    {
        // Долгое соединение: у штатного retry_after 90 с, письмо разбирается дольше.
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(Sync $sync): void
    {
        if ($account = Account::where('is_active', true)->find($this->accountId)) {
            $sync->account($account);
        }
    }
}
