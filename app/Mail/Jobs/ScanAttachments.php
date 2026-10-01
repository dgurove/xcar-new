<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Threads;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * «✨ Распознать» (`ScanController`): прочитать отмеченные файлы цепочки одной пачкой (`DocumentText::read`), по
 * каждому готовому — событие `scan` человеку, окно перечитывается. Потом письма с этими файлами перечитываются
 * (`ReadLetter`: прочитанное лежит в кеше и идёт в разбор как слой), цепочка сворачивается заново — марка из скана
 * встаёт в карточку, даже если окно закрыли, не выбрав. Пока задача идёт, у цепочки метка `busy()` — окно крутит
 * плитки и не показывает поля, которые ещё сдвинет перечитка. Очередь `scan` на лёгком воркере: человек ждёт.
 */
final class ScanAttachments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param list<int> $ids */
    public function __construct(public int $candidateId, public array $ids, public int $userId)
    {
        $this->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return $this->candidateId.':'.implode(',', $this->ids);
    }

    /** Метка «читается»: ставит контроллер при постановке, снимает задача. Час — на случай упавшего воркера. */
    public static function busy(int $candidateId, ?bool $on = null): bool
    {
        if ($on === null) {
            return Cache::has("scan:{$candidateId}");
        }
        $on ? Cache::put("scan:{$candidateId}", true, 3600) : Cache::forget("scan:{$candidateId}");

        return $on;
    }

    public function handle(ReadLetter $reader, ChainBuilder $chains, Threads $threads, Publisher $publish): void
    {
        $ping = fn () => $publish(Topics::user($this->userId), 'scan', ['candidate' => $this->candidateId]);
        try {
            $candidate = Candidate::find($this->candidateId);
            $attachments = Attachment::with('message.account')->whereIn('id', $this->ids)->get();
            if (! $candidate || $attachments->isEmpty()) {
                return;
            }
            DocumentText::read($attachments, $ping);
            foreach (Message::with(['account', 'attachments', 'thread'])->whereIn('id', $attachments->pluck('message_id')->unique())->get() as $message) {
                $reader->apply($message);
                if ($message->thread) {
                    $threads->refresh($message->thread);
                }
            }
            $chains->fold($candidate->refresh());
        } finally {
            self::busy($this->candidateId, false);
            $ping();
        }
    }
}
