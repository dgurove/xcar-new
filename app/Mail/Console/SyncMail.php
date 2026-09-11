<?php

namespace App\Mail\Console;

use App\Mail\Account;
use App\Mail\Sync;
use App\Support\HoldsSingleRun;
use Illuminate\Console\Command;
use Throwable;

class SyncMail extends Command
{
    use HoldsSingleRun;

    protected $signature = 'mail:sync {--account= : slug ящика} {--full : все папки, не сверяясь со снимком}';

    protected $description = 'Забирает новые письма из ящиков';

    public function handle(Sync $sync): int
    {
        return $this->holdingSingleRun('mail:sync:'.($this->option('account') ?: 'all'), function () use ($sync) {
            $accounts = Account::where('is_active', true)->when($this->option('account'), fn ($q, $slug) => $q->where('slug', $slug))->get();
            if ($accounts->isEmpty()) {
                $this->line('Активных ящиков нет');

                return self::SUCCESS;
            }
            $failed = false;
            foreach ($accounts as $account) {
                try {
                    $this->line("{$account->email}: новых — ".$sync->account($account, (bool) $this->option('full')));
                } catch (Throwable $e) {
                    $failed = true;
                    $this->error("{$account->email}: {$e->getMessage()}");
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }
}
