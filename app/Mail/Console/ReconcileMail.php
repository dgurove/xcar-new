<?php

namespace App\Mail\Console;

use App\Mail\Account;
use App\Mail\Imap;
use App\Mail\Jobs\ParseMessage;
use App\Mail\Message;
use App\Mail\ParseState;
use App\Mail\Sync;
use Illuminate\Console\Command;
use Throwable;

/** Ночная сверка: без CONDSTORE флаги приходится перечитывать; застрявший разбор — заново в очередь. */
class ReconcileMail extends Command
{
    protected $signature = 'mail:reconcile {--days=30}';

    protected $description = 'Сверяет флаги писем с сервером и добирает неразобранное';

    public function handle(Sync $sync): int
    {
        $since = now()->subDays((int) $this->option('days'));
        foreach (Account::where('is_active', true)->get() as $account) {
            $imap = new Imap($account);
            $checked = 0;
            try {
                foreach ($account->folders()->where('is_syncable', true)->get() as $folder) {
                    Message::where('folder_id', $folder->id)->whereNotNull('imap_uid')->where('date_at', '>=', $since)
                        ->chunk(200, function ($chunk) use ($imap, $folder, $sync, &$checked) {
                            $flags = $imap->flags($folder->path, $chunk->pluck('imap_uid')->all());
                            foreach ($chunk as $message) {
                                if (isset($flags[$message->imap_uid])) {
                                    $sync->applyFlags($message, $flags[$message->imap_uid]);
                                    $checked++;
                                }
                            }
                        });
                }
                $this->line("{$account->email}: сверено {$checked}");
            } catch (Throwable $e) {
                $account->markFailed($e->getMessage());
                $this->error("{$account->email}: {$e->getMessage()}");
            } finally {
                $imap->disconnect();
            }
        }
        $stuck = Message::where('parse_state', ParseState::Pending)->where('created_at', '<', now()->subMinutes(30))->limit(500)->pluck('id');
        foreach ($stuck as $id) {
            ParseMessage::dispatch($id);
        }
        if ($stuck->isNotEmpty()) {
            $this->line("Возвращено в разбор: {$stuck->count()}");
        }

        return self::SUCCESS;
    }
}
