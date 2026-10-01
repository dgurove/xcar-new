<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Attachment;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scan\Subjects;
use App\Mail\Threads;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * «✨ Распознать» (`ScanController`): прочитать отмеченные файлы цепочки, ТС или предложения одной пачкой
 * (`DocumentText::read`), по каждому готовому — событие `scan` тем, кто может открыть окно (`Subject::topic`): у всех,
 * кто его открыл, оно перечитывается. Потом письма с этими файлами перечитываются (`ReadLetter`: прочитанное лежит в
 * кеше и идёт в разбор как слой), цепочка сворачивается заново (у ТС — дело перечитывается) — марка из скана встаёт в
 * карточку, даже если окно закрыли.
 * Пока файл в работе, на нём метка (`reading`): окно крутит его и не показывает поля, которые ещё сдвинет перечитка.
 * Метка у каждого файла своя — две пачки одной цепочки друг другу её не снимают; живёт не дольше задачи, а убитая
 * по таймауту задача снимает её в `failed`. Очередь `scan` на лёгком воркере: человек ждёт.
 */
final class ScanAttachments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Файлов за одно нажатие: ~6 с на страницу на проде, пачка укладывается в таймаут с запасом. */
    public const MAX_FILES = 24;

    public int $timeout = 900;

    public int $tries = 1;

    private ?string $topic = null;

    /** @param string $subject `c:93` — цепочка, `v:266` — ТС, `o:512` — предложение (`Scan\Subjects`) @param list<int> $ids */
    public function __construct(public string $subject, public array $ids)
    {
        $this->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return $this->subject.':'.implode(',', $this->ids);
    }

    /** Метка «читается» на файлах пачки: ставит контроллер при постановке, снимает задача. */
    public static function mark(array $ids, bool $on = true): void
    {
        foreach ($ids as $id) {
            $on ? Cache::put("scan:a:{$id}", true, 960) : Cache::forget("scan:a:{$id}");
        }
    }

    /** Какие из файлов сейчас в работе. @param iterable<Attachment> $attachments @return list<int> */
    public static function reading(iterable $attachments): array
    {
        return collect($attachments)->pluck('id')->filter(fn ($id) => Cache::has("scan:a:{$id}"))->values()->all();
    }

    public function handle(ReadLetter $reader, Threads $threads, Publisher $publish): void
    {
        try {
            $subject = Subjects::find($this->subject);
            $attachments = Attachment::with('message.account')->whereIn('id', $this->ids)->get();
            if (! $subject || $attachments->isEmpty()) {
                return;
            }
            DocumentText::read($attachments, fn () => $this->ping($publish), $this->timeout - 60);
            foreach (Message::with(['account', 'attachments', 'thread'])->whereIn('id', $attachments->pluck('message_id')->unique())->get() as $message) {
                $reader->apply($message);
                if ($message->thread) {
                    $threads->refresh($message->thread);
                }
            }
            $subject->refresh();
        } finally {
            self::mark($this->ids, false);
            $this->ping($publish);
        }
    }

    /** Убита по таймауту или упала до `finally` — метки снять, окно отпустить. */
    public function failed(?Throwable $e): void
    {
        self::mark($this->ids, false);
        $this->ping(app(Publisher::class));
    }

    private function ping(Publisher $publish): void
    {
        $this->topic ??= Subjects::find($this->subject)?->topic() ?? Topics::STAFF;
        $publish($this->topic, 'scan', ['subject' => $this->subject]);
    }
}
