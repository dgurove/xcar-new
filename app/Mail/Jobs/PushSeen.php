<?php

namespace App\Mail\Jobs;

use App\Mail\Imap;
use App\Mail\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Прочитано» пачкой — в сам ящик, одним входом на ящик (`PushFlag` — вход на письмо): «Прочитать все» и разовая
 * чистка старых непрочитанных CRM (17.11.2026). Без этого `Sync::applyFlags` вернул бы письмам прежний флаг ящика.
 */
final class PushSeen implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $ids */
    public function __construct(public array $ids, public bool $read = true)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(): void
    {
        $messages = Message::with(['account', 'folder'])->whereIn('id', $this->ids)->whereNotNull('imap_uid')->whereNotNull('folder_id')->get();
        foreach ($messages->groupBy('account_id') as $group) {
            $imap = new Imap($group->first()->account, 30);
            try {
                foreach ($group as $message) {
                    $imap->setFlag($message->folder->path, $message->imap_uid, '\\Seen', $this->read);
                }
            } catch (Throwable $e) {
                Log::warning('Почта: «прочитано» пачкой не ушло на сервер', ['account' => $group->first()->account_id, 'error' => $e->getMessage()]);
            } finally {
                $imap->disconnect();
            }
        }
    }
}
