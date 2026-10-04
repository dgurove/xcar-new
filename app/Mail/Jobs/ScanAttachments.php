<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Attachment;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scan\Paper;
use App\Mail\Scan\ScanFile;
use App\Mail\Scan\Subject;
use App\Mail\Scan\Subjects;
use App\Mail\Threads;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Чтение файлов цепочки, ТС или предложения по порядку (`DocumentText::read`): читалка «Завести» (`x-mail.reader`) и
 * окно «Из документов» (`ScanController`). По каждому готовому файлу — событие `scan` с его номером тем, кто может
 * открыть предмет (`Subject::topic`): читалка заполняет поля, окно перечитывается. «Стоп» (`stop`) гасит `ocr` после
 * текущей страницы. Потом письма с этими файлами перечитываются (`ReadLetter`: прочитанное лежит в
 * кеше и идёт в разбор как слой), цепочка сворачивается заново (у ТС — дело перечитывается) — марка из скана встаёт в
 * карточку, даже если окно закрыли.
 * Файлы — вложения писем (`812`) и документы предложения (`m45`, `Scan\Paper`): письма перечитываются только у первых.
 * Пока файл в работе, на нём метка (`reading`): окно крутит его и не показывает поля, которые ещё сдвинет перечитка.
 * Метка у каждого файла своя — две пачки одной цепочки друг другу её не снимают; живёт не дольше задачи, а убитая
 * по таймауту задача снимает её в `failed`. Очередь `scan` — своим соединением `database-scan` (retry_after 960 больше
 * таймаута) и своим работником `queue-scan`: человек ждёт, а уведомления не ждут чтения.
 */
final class ScanAttachments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Файлов за одно нажатие: ~6 с на страницу на проде, пачка укладывается в таймаут с запасом. */
    public const MAX_FILES = 24;

    public int $timeout = 900;

    public int $tries = 1;

    /** Замок «такая пачка уже стоит» — не дольше задачи: не поставленная (сбой при постановке) не держит его вечно. */
    public int $uniqueFor = 960;

    private ?string $topic = null;

    /**
     * @param  string  $subject  `c:93` — цепочка, `v:266` — ТС, `o:512` — предложение (`Scan\Subjects`)
     * @param  list<int|string>  $ids  `ScanFile::scanId`; числа — вложения из задач до 02.10.2026
     */
    public function __construct(public string $subject, public array $ids)
    {
        $this->onConnection('database-scan')->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return $this->subject.':'.implode(',', $this->ids);
    }

    /** Метка «читается» на файлах пачки: ставит контроллер при постановке, снимает задача — по файлу, как прочтёт. */
    public static function mark(array $ids, bool $on = true): void
    {
        foreach ($ids as $id) {
            $on ? Cache::put("scan:a:{$id}", true, 960) : Cache::forget("scan:a:{$id}");
        }
    }

    /** Какие из файлов сейчас в работе. @param iterable<ScanFile> $files @return list<string> */
    public static function reading(iterable $files): array
    {
        return collect($files)->map->scanId()->filter(fn ($id) => Cache::has("scan:a:{$id}"))->values()->all();
    }

    /** Файл читался и не прочёлся (упал, не уложился) — читалка покажет «не прочитан» до нового чтения. */
    public static function lost(string $id): bool
    {
        return Cache::has("scan:x:{$id}");
    }

    /**
     * Поставить файлы предмета в чтение по порядку: снимает «Стоп», ставит метки, кладёт задачу. Прочитанные не
     * ставятся — их текст в кеше.
     *
     * @param  list<string>  $ids
     */
    public static function enqueue(Subject $subject, array $ids): void
    {
        Cache::forget("scan:stop:{$subject->key()}");
        if ($ids) {
            self::mark($ids);
            self::dispatch($subject->key(), $ids);
        }
    }

    /**
     * «Стоп» читалки: задача гасит `ocr` после текущей страницы. Метка живёт сутки — пока она стоит, читалка сама не
     * начинает чтение заново (перезагрузили страницу — не читаем то, что человек остановил).
     */
    public static function stop(Subject $subject): void
    {
        Cache::put("scan:stop:{$subject->key()}", true, 86400);
    }

    public static function stopped(Subject $subject): bool
    {
        return Cache::has("scan:stop:{$subject->key()}");
    }

    /** Задача предмета взята работником (иначе — ждёт в очереди за чужим чтением). */
    public static function running(Subject $subject): bool
    {
        return Cache::has("scan:run:{$subject->key()}");
    }

    /** Работник занят чтением: задача взята из очереди `scan` и ещё не кончилась. Свободен — наша вот-вот начнётся. */
    public static function workerBusy(): bool
    {
        return DB::connection(config('queue.connections.database-scan.connection'))->table(config('queue.connections.database-scan.table', 'jobs'))->where('queue', 'scan')->whereNotNull('reserved_at')->exists();
    }

    public function handle(ReadLetter $reader, Threads $threads, Publisher $publish): void
    {
        $stop = "scan:stop:{$this->subject}";
        try {
            $subject = Subjects::find($this->subject);
            if (! $subject || Cache::has($stop)) {
                return;
            }
            $ids = collect($this->ids)->map(fn ($id) => (string) $id);
            $attachments = Attachment::with('message.account')->whereIn('id', $ids->filter(fn ($id) => ctype_digit($id))->all())->get();
            $papers = Paper::wrap(Media::whereIn('id', $ids->filter(fn ($id) => str_starts_with($id, 'm'))->map(fn ($id) => (int) substr($id, 1))->all())->get());
            // В том порядке, в каком их поставили: первым — где машина вернее всего (`Files::rank`).
            $byId = collect([...$attachments, ...$papers])->keyBy(fn (ScanFile $f) => $f->scanId());
            $files = $ids->map(fn ($id) => $byId[$id] ?? null)->filter()->values();
            if ($files->isEmpty()) {
                return;
            }
            Cache::put("scan:run:{$this->subject}", true, 960);
            $this->ping($publish);
            DocumentText::read($files, function (ScanFile $file, ?string $text) use ($publish) {
                self::mark([$file->scanId()], false);
                $text === null ? Cache::put("scan:x:{$file->scanId()}", true, 86400) : Cache::forget("scan:x:{$file->scanId()}");
                $this->ping($publish, $file->scanId());
            }, $this->timeout - 60, fn () => Cache::has($stop));
            foreach (Message::with(['account', 'attachments', 'thread'])->whereIn('id', $attachments->pluck('message_id')->unique())->get() as $message) {
                $reader->apply($message);
                if ($message->thread) {
                    $threads->refresh($message->thread);
                }
            }
            $subject->refresh();
        } finally {
            self::mark($this->ids, false);
            Cache::forget("scan:run:{$this->subject}");
            $this->ping($publish);
        }
    }

    /** Убита по таймауту или упала до `finally` — метки снять, окно отпустить. */
    public function failed(?Throwable $e): void
    {
        self::mark($this->ids, false);
        Cache::forget("scan:run:{$this->subject}");
        $this->ping(app(Publisher::class));
    }

    /** Событие `scan` тем, кто может открыть предмет: окно и читалка перечитываются. `$file` — какой файл готов. */
    private function ping(Publisher $publish, ?string $file = null): void
    {
        $this->topic ??= Subjects::find($this->subject)?->topic() ?? Topics::STAFF;
        $publish($this->topic, 'scan', array_filter(['subject' => $this->subject, 'file' => $file]));
    }
}
