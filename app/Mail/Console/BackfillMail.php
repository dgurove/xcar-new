<?php

namespace App\Mail\Console;

use App\Mail\Account;
use App\Mail\Folder;
use App\Mail\FolderKind;
use App\Mail\Imap;
use App\Mail\Message;
use App\Mail\Sync;
use App\Support\HoldsSingleRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * История ящика: письма старше уже забранных — заголовки, текст, опись вложений. Файлы на диск не ложатся
 * (правило «Файлы из писем с» у ящика), людей не дёргает. Идёт по UID снизу вверх, курсор `backfill_uid`
 * в папке — прерванный запуск продолжается с места. Сначала входящие, потом отправленные: заявка раньше ответа.
 */
class BackfillMail extends Command
{
    use HoldsSingleRun;

    protected $signature = 'mail:backfill {--account=storage : slug ящика} {--limit=0 : сколько писем за запуск, 0 — все} {--folder= : только эта папка (path)}';

    protected $description = 'Забирает историю ящика без файлов: письма старше уже забранных';

    public function handle(Sync $sync): int
    {
        return $this->holdingSingleRun('mail:backfill', function () use ($sync) {
            $account = Account::where('slug', $this->option('account'))->first();
            if (! $account) {
                $this->error('Ящика нет: '.$this->option('account'));

                return self::FAILURE;
            }
            if (! $account->files_from) {
                $this->warn("У ящика {$account->email} не задано «Файлы из писем с» — история закрепит все вложения на диск");
            }
            $limit = (int) $this->option('limit');
            $folders = $account->folders()->whereIn('kind', [FolderKind::Inbox, FolderKind::Sent])->orderByRaw("kind = 'inbox' desc")
                ->when($this->option('folder'), fn ($q, $path) => $q->where('path', $path))->get();
            $total = 0;
            $imap = null;
            $failed = [];
            try {
                foreach ($folders as $folder) {
                    // Ящик на mail.ru за часы истории обрывает соединение — переподключаемся и продолжаем с курсора.
                    $ok = false;
                    for ($attempt = 1; $attempt <= 20; $attempt++) {
                        $imap ??= new Imap($account, 300);
                        try {
                            $uids = $this->pending($folder, $imap);
                            if ($limit > 0) {
                                $uids = array_slice($uids, 0, max(0, $limit - $total));
                            }
                            if (! $uids) {
                                $this->line("{$folder->name}: истории нет");
                                $ok = true;
                                break;
                            }
                            $this->line("{$folder->name}: ".count($uids)." писем, uid {$uids[0]}…".end($uids));
                            $started = microtime(true);
                            $done = 0;
                            $stored = $sync->backfill($account, $folder, $imap, $uids, function (int $uid, int $stored) use ($folder, $started, &$done) {
                                if (++$done % 50 === 0) {
                                    $this->line(sprintf('  %s: uid %d, записано %d, %.1f с/письмо', $folder->name, $uid, $stored, (microtime(true) - $started) / $done));
                                }
                            });
                            $total += count($uids);
                            $this->line("{$folder->name}: записано {$stored}");
                            $ok = true;
                            break;
                        } catch (Throwable $e) {
                            $this->warn("{$folder->name}: {$e->getMessage()} — попытка {$attempt}, переподключаюсь");
                            Log::warning('Почта: история оборвалась', ['folder' => $folder->path, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                            try {
                                $imap?->disconnect();
                            } catch (Throwable) {
                            }
                            $imap = null;
                            sleep(min(60, 5 * $attempt));
                        }
                    }
                    if (! $ok) {
                        $failed[] = $folder->name;
                    }
                    if ($limit > 0 && $total >= $limit) {
                        break;
                    }
                }
            } finally {
                try {
                    $imap?->disconnect();
                } catch (Throwable) {
                }
            }
            if ($failed) {
                $this->error('Не добрано: '.implode(', ', $failed).' — запустите снова, курсор на месте');

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }

    /** UID папки, которых в базе нет: выше курсора истории и ниже первого забранного обычным синком. @return list<int> */
    private function pending(Folder $folder, Imap $imap): array
    {
        $floor = (int) $folder->backfill_uid;
        // Потолок — первое письмо обычного синка; уже забранная история лежит ниже курсора и не в счёт.
        $ceiling = Message::where('folder_id', $folder->id)->where('imap_uid', '>', $floor)->min('imap_uid');
        $uids = $imap->uids($folder->path, $floor + 1);

        return array_values(array_filter($uids, fn (int $uid) => $ceiling === null || $uid < $ceiling));
    }
}
