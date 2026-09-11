<?php

namespace App\Mail\Console;

use App\Mail\Account;
use App\Mail\FolderKind;
use App\Mail\Imap;
use App\Mail\Jobs\SyncAccount;
use App\Support\HoldsSingleRun;
use Illuminate\Console\Command;
use Throwable;

/**
 * IMAP IDLE. Без --account — надсмотрщик: по процессу на активный ящик,
 * упавший поднимается, список ящиков перечитывается раз в минуту. Живёт
 * сервисом compose `mail-watch`, а не в расписании: долгая команда в cron
 * однажды накопила сотни процессов и уронила сервер.
 */
class WatchMail extends Command
{
    use HoldsSingleRun;

    protected $signature = 'mail:watch {--account= : slug ящика} {--ttl=290 : сколько секунд слушать}';

    protected $description = 'Ждёт новые письма по IMAP IDLE и сразу ставит синхронизацию в очередь';

    public function handle(): int
    {
        return $this->option('account') ? $this->one($this->option('account')) : $this->supervise();
    }

    private function one(string $slug): int
    {
        $account = Account::where('is_active', true)->where('slug', $slug)->first();
        if (! $account) {
            $this->line("{$slug}: ящик выключен");

            return self::SUCCESS;
        }
        $inbox = $account->folderOf(FolderKind::Inbox);
        if (! $inbox) {
            $this->line("{$account->email}: папки ещё не считаны, первую синхронизацию сделает mail:sync");

            return self::SUCCESS;
        }

        return $this->holdingSingleRun('mail:watch:'.$slug, function () use ($account, $inbox) {
            $imap = new Imap($account);
            $this->line("{$account->email}: слушаю «{$inbox->name}»");
            try {
                $imap->idle($inbox->path, function () use ($account) {
                    $this->line('Пришло письмо — синхронизация в очередь');
                    SyncAccount::dispatch($account->id);
                }, (int) $this->option('ttl'));
            } catch (Throwable $e) {
                // Обрыв IDLE — обычное дело: mail.ru рвёт соединение когда захочет.
                $account->markFailed($e->getMessage());
                $this->error("{$account->email}: {$e->getMessage()}");

                return self::FAILURE;
            } finally {
                $imap->disconnect();
            }

            return self::SUCCESS;
        });
    }

    private function supervise(): int
    {
        $children = [];
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        while (true) {
            $slugs = Account::where('is_active', true)->whereHas('folders', fn ($f) => $f->where('kind', FolderKind::Inbox))->pluck('slug')->all();
            foreach ($children as $slug => $proc) {
                $status = proc_get_status($proc);
                if (! $status['running'] || ! in_array($slug, $slugs, true)) {
                    if ($status['running']) {
                        proc_terminate($proc);
                    }
                    proc_close($proc);
                    unset($children[$slug]);
                }
            }
            foreach ($slugs as $slug) {
                if (! isset($children[$slug])) {
                    $proc = proc_open([$php, $artisan, 'mail:watch', "--account={$slug}", '--ttl='.$this->option('ttl')], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
                    if ($proc) {
                        $children[$slug] = $proc;
                        $this->line("{$slug}: наблюдатель запущен");
                    }
                }
            }
            if (! $slugs) {
                $this->line('Активных ящиков нет, жду');
            }
            sleep(30);
        }
    }
}
