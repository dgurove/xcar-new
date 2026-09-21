<?php

namespace App\Mail\Console;

use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Thread;
use App\Mail\Threads;
use Illuminate\Console\Command;

/**
 * Перечитать письма читалкой текущей версии: после правки правил разбора поднимается `ReadLetter::VERSION`,
 * и команда проходит только письма со старой версией — чанками, можно прерывать, почта не останавливается.
 * После — `mail:rebuild`, если менялись номера-тождества, иначе достаточно свёртки цепочек (`--fold`).
 */
class ReadMail extends Command
{
    protected $signature = 'mail:read {--all : все письма, не только со старой версией} {--limit=0}';

    protected $description = 'Перечитать письма читалкой текущей версии (поля, номера, смысл)';

    public function handle(ReadLetter $reader, Threads $threads): int
    {
        $query = Message::with(['account', 'attachments'])->whereNotNull('thread_id')->when(! $this->option('all'), fn ($q) => $q->where('parser_version', '<', ReadLetter::VERSION))->orderBy('id');
        $total = (clone $query)->count();
        $this->line("Писем к чтению: {$total}");
        $done = 0;
        $threadIds = [];
        $started = microtime(true);
        $query->chunkById(200, function ($messages) use ($reader, &$done, &$threadIds, $started, $total) {
            foreach ($messages as $m) {
                $reader->apply($m);
                $threadIds[$m->thread_id] = true;
                $done++;
            }
            $this->line(sprintf('  %d/%d, %.0f мс/письмо', $done, $total, (microtime(true) - $started) / max(1, $done) * 1000));

            return (int) $this->option('limit') <= 0 || $done < (int) $this->option('limit');
        });
        $n = 0;
        foreach (array_chunk(array_keys($threadIds), 500) as $chunk) {
            foreach (Thread::whereIn('id', $chunk)->get() as $thread) {
                $threads->refresh($thread);
                $n++;
            }
        }
        $this->line("Прочитано: {$done}, веток обновлено: {$n}");

        return self::SUCCESS;
    }
}
