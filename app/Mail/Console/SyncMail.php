<?php

namespace App\Mail\Console;

use App\Mail\Account;
use App\Mail\Jobs\SendMessage;
use App\Mail\Jobs\ParseMessage;
use App\Mail\Message;
use App\Mail\ParseState;
use Illuminate\Support\Facades\Cache;
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
            SendMessage::failStuck();
            $failed = false;
            foreach ($accounts as $account) {
                try {
                    $this->line("{$account->email}: новых — ".$sync->account($account, (bool) $this->option('full')));
                } catch (Throwable $e) {
                    $failed = true;
                    $this->error("{$account->email}: {$e->getMessage()}");
                }
            }

            // Письмо завели, а разобрать не успели (убили посреди, обрыв) — снова в разбор через 3 минуты, а не ночью в 04:10
            // (`mail:reconcile`, 26.11.2026). Одно и то же письмо — не чаще раза в 15 минут: кривое не забивает очередь почты.
            if (! $this->option('account')) {
                $stuck = Message::where('parse_state', ParseState::Pending)->whereBetween('created_at', [now()->subDay(), now()->subMinutes(3)])->limit(100)->pluck('id');
                foreach ($stuck as $id) {
                    Cache::add('mail:reparse:'.$id, true, 900) && ParseMessage::dispatch($id);
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }
}
