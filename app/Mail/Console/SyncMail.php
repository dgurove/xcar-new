<?php

namespace App\Mail\Console;

use App\Mail\Account;
use App\Mail\Jobs\SendMessage;
use App\Mail\Jobs\ParseMessage;
use App\Mail\Message;
use App\Mail\ParseState;
use Illuminate\Support\Facades\Cache;
use App\Mail\Sync;
use App\Mail\Ingest;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\MailServiceNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Support\HoldsSingleRun;
use Illuminate\Console\Command;
use Throwable;

class SyncMail extends Command
{
    use HoldsSingleRun;

    protected $signature = 'mail:sync {--account= : slug ящика} {--full : все папки, не сверяясь со снимком}';

    protected $description = 'Забирает новые письма из ящиков';

    public function handle(Sync $sync, Ingest $ingest): int
    {
        return $this->holdingSingleRun('mail:sync:'.($this->option('account') ?: 'all'), function () use ($sync, $ingest) {
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
                $this->resume($ingest);
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }

    /** Сколько раз дочитка берётся за одно письмо: падает и дальше — помечается дообработанным, владельцу строкой. */
    private const RESUME_TRIES = 3;

    /**
     * Письмо разобрано, а его следствия не дошли до конца (убили посреди, слушатель упал; 26.11.2026) — дообработать
     * (`Ingest::handle`): от трёх минут до двух суток, по 20, старые первыми. Ядовитое — после трёх падений помечается, ошибка
     * владельцу: иначе крутилось бы каждую минуту.
     */
    public function resume(Ingest $ingest): void
    {
        $left = Message::with(['account', 'thread', 'attachments'])->where('parse_state', ParseState::Parsed)->whereNull('handled_at')
            ->whereBetween('created_at', [max(now()->subDays(2), Carbon::createFromTimestamp((int) Cache::get('mail:resume:since', 0))), now()->subMinutes(3)])
            ->orderBy('id')->limit(20)->get();
        foreach ($left as $message) {
            $tries = (int) Cache::get('mail:resume:'.$message->id) + 1;
            Cache::put('mail:resume:'.$message->id, $tries, now()->addDay());
            try {
                $ingest->handle($message);
            } catch (Throwable $e) {
                report($e);
                if ($tries >= self::RESUME_TRIES) {
                    Message::whereKey($message->id)->update(['handled_at' => now()]);
                    NotifyOwner::dispatch(new MailServiceNotice('Письмо не дообработалось', [
                        ($message->from_name ?: $message->from_email).': '.($message->subject ?: 'без темы'),
                        Str::limit($e->getMessage(), 200),
                    ], (int) $message->thread_id));
                }
            }
        }
    }
}
