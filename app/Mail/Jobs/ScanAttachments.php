<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Attachment;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scan\AutoScan;
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

    /**
     * Два захода: выкладка пересоздаёт контейнер посреди чтения — задача осталась взятой, и с одним заходом её списывали
     * в упавшие, не начав второй раз (07.10.2026). Второй заход читает только непрочитанное — прочитанное уже в кеше.
     */
    public int $tries = 2;

    /** Замок «такая пачка уже стоит» — не дольше задачи: не поставленная (сбой при постановке) не держит его вечно. */
    public int $uniqueFor = 960;

    private ?string $topic = null;

    /**
     * @param  string  $subject  `c:93` — цепочка, `v:266` — ТС, `o:512` — предложение (`Scan\Subjects`)
     * @param  list<int|string>  $ids  `ScanFile::scanId`; числа — вложения из задач до 02.10.2026
     */
    /**
     * Очереди чтения: `scan` — человек ждёт (читалка «Завести», окно «Из документов»), `scan-bg` — фон (ТС заявки и черновик
     * из письма, дочитка часов). Работник берёт фон, только когда людских нет (`--queue=scan,scan-bg`): 12.10.2026 пачка
     * в 80 черновиков из писем держала очередь час, и «Завести» руками стояло за ней.
     */
    public const QUEUES = ['scan', 'scan-bg'];

    public function __construct(public string $subject, public array $ids, bool $background = false)
    {
        $this->onConnection('database-scan')->onQueue($background ? 'scan-bg' : 'scan');
    }

    public function uniqueId(): string
    {
        return $this->subject.':'.implode(',', $this->ids);
    }

    /** Метка «читается» на файлах пачки: ставит контроллер при постановке, снимает задача — по файлу, как прочтёт. */
    /**
     * Метка «файл читается». 12.10.2026 очередь стояла час, метка в 960 с гасла раньше, и дочитка часов ставила те же файлы
     * снова — тогда её подняли до 6 ч, и метка-сирота (задача умерла) держала предмет «читается» 6 ч. С 26.11.2026 дубли
     * не ставятся иначе (фон смотрит невзятую задачу предмета, `queuedFor`), метка — полчаса, задача на старте её обновляет.
     * Снимают её `finally` и `failed`.
     */
    private const MARK_TTL = 1800;

    /** Файл читался и не прочёлся — не повторять столько: час, а не сутки (дочитка часов возьмёт его снова). */
    private const LOST_TTL = 3600;

    /**
     * Стоит ли в очереди не взятая задача этого предмета: дочитка и автозапуск не ставят вторую. В теле задачи —
     * сериализованная команда: «subject\";s:5:\"o:605\"» (между именем и значением — `\";s:N:\"`).
     */
    public static function queuedFor(Subject $subject): bool
    {
        $conn = config('queue.connections.database-scan');
        $key = $subject->key();

        return DB::connection($conn['connection'] ?? null)->table($conn['table'] ?? 'jobs')
            ->whereIn('queue', self::QUEUES)->whereNull('reserved_at')
            ->where('payload', 'like', '%subject__;s:'.strlen($key).':__'.$key.'__;%')->exists();
    }

    public static function mark(array $ids, bool $on = true): void
    {
        foreach ($ids as $id) {
            $on ? Cache::put("scan:a:{$id}", true, self::MARK_TTL) : Cache::forget("scan:a:{$id}");
        }
    }

    /**
     * Какие из файлов сейчас в работе: метка «читается» или файл в задаче, что стоит в очереди. Метка живёт полчаса, а фон
     * бывает дольше (26.11.2026): по одной метке ждущий файл считался свободным — ставился второй раз, а предмет «прочитанным».
     *
     * @param  iterable<ScanFile>  $files
     * @return list<string>
     */
    public static function reading(iterable $files): array
    {
        $queued = null;

        return collect($files)->map->scanId()->filter(function ($id) use (&$queued) {
            if (Cache::has("scan:a:{$id}")) {
                return true;
            }
            $queued ??= self::queuedIds();

            return isset($queued[(string) $id]);
        })->values()->all();
    }

    /**
     * Файлы задач чтения, что ждут в очереди (их единицы — `scan` и `scan-bg`). Взятые — нет: у них свои метки (задача ставит
     * их на старте), а своя строка взятой задачи живёт до конца `handle` — с ней задача считала бы себя «ещё читается» и не
     * подводила итог.
     *
     * @return array<string, true>
     */
    public static function queuedIds(): array
    {
        $conn = config('queue.connections.database-scan');
        $out = [];
        foreach (DB::connection($conn['connection'] ?? null)->table($conn['table'] ?? 'jobs')->whereIn('queue', self::QUEUES)->whereNull('reserved_at')->pluck('payload') as $payload) {
            $command = json_decode($payload, true)['data']['command'] ?? null;
            $job = is_string($command) && str_contains($command, 'ScanAttachments') ? @unserialize($command, ['allowed_classes' => [self::class]]) : null;
            foreach ($job instanceof self ? $job->ids : [] as $id) {
                $out[(string) $id] = true;
            }
        }

        return $out;
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
    public static function enqueue(Subject $subject, array $ids, bool $background = false): void
    {
        Cache::forget("scan:stop:{$subject->key()}");
        if ($ids) {
            self::mark($ids);
            self::dispatch($subject->key(), $ids, $background);
        }
    }

    /** Человек открыл предмет, чьи файлы ждут в фоне, — его задачи вперёд, к людским: он смотрит на читалку. */
    public static function promote(Subject $subject): void
    {
        $conn = config('queue.connections.database-scan');
        $key = $subject->key();
        DB::connection($conn['connection'] ?? null)->table($conn['table'] ?? 'jobs')
            ->where('queue', 'scan-bg')->whereNull('reserved_at')
            ->where('payload', 'like', '%subject__;s:'.strlen($key).':__'.$key.'__;%')
            ->update(['queue' => 'scan']);
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
        return DB::connection(config('queue.connections.database-scan.connection'))->table(config('queue.connections.database-scan.table', 'jobs'))->whereIn('queue', self::QUEUES)->whereNotNull('reserved_at')->exists();
    }

    public function handle(ReadLetter $reader, Threads $threads, Publisher $publish): void
    {
        $stop = "scan:stop:{$this->subject}";
        $rest = [];
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
            self::mark($files->map->scanId()->all());
            $this->ping($publish);
            $answered = [];
            DocumentText::read($files, function (ScanFile $file, ?string $text, ?int $turn = null, bool $skipped = false) use ($publish, &$rest, &$answered) {
                $answered[$file->scanId()] = true;
                if ($skipped) {
                    // Не дошли по сроку задачи — не сбой: следующей задачей сразу (иначе ждали бы дочитки часов).
                    $rest[] = $file->scanId();

                    return;
                }
                self::mark([$file->scanId()], false);
                $text === null ? Cache::put("scan:x:{$file->scanId()}", true, self::LOST_TTL) : Cache::forget("scan:x:{$file->scanId()}");
                $this->ping($publish, $file->scanId());
                // Предмет читается сам (ТС заявки, черновик из письма) — строка списка на шаг вперёд.
                AutoScan::advance($this->subject);
            }, $this->timeout - 60, fn () => Cache::has($stop));
            // Файл, на котором чтение упало исключением (битый PDF), ответа не получил: «не прочёлся» на час — иначе дочитка
            // часов ставила бы его снова каждые 5 минут.
            if (! Cache::has($stop)) {
                foreach ($files as $file) {
                    if (! isset($answered[$file->scanId()]) && ! DocumentText::isCached($file)) {
                        Cache::put("scan:x:{$file->scanId()}", true, self::LOST_TTL);
                    }
                }
            }
            if ($rest && ! Cache::has($stop)) {
                self::dispatch($this->subject, $rest, $this->queue === 'scan-bg');
            }
            foreach (Message::with(['account', 'attachments', 'thread'])->whereIn('id', $attachments->pluck('message_id')->unique())->get() as $message) {
                $reader->apply($message);
                if ($message->thread) {
                    $threads->refresh($message->thread);
                }
            }
            $subject->refresh();
        } finally {
            // Недочитанное по сроку ушло следующей задачей — его метки остаются: предмет всё ещё читается.
            self::mark(array_values(array_diff(array_map('strval', $this->ids), $rest)), false);
            Cache::forget("scan:run:{$this->subject}");
            // ТС завела почта — найденное вписывается само, без формы (`AutoScan`). После снятия меток: недочитанное
            // по сроку иначе держало бы предмет «читается», и итог не подвёл бы никто.
            if (isset($subject)) {
                AutoScan::finished($subject);
            }
            // И ТС, чьи файлы читала эта задача чужого предмета (цепочка «Из писем», исчезнувший предмет).
            AutoScan::settle($this->ids);
            $this->ping($publish);
        }
    }

    /** Убита по таймауту или упала до `finally` — метки снять, окно отпустить. */
    public function failed(?Throwable $e): void
    {
        self::mark($this->ids, false);
        Cache::forget("scan:run:{$this->subject}");
        AutoScan::abandon($this->subject);
        AutoScan::settle($this->ids);
        $this->ping(app(Publisher::class));
    }

    /** Событие `scan` тем, кто может открыть предмет: окно и читалка перечитываются. `$file` — какой файл готов. */
    private function ping(Publisher $publish, ?string $file = null): void
    {
        $this->topic ??= Subjects::find($this->subject)?->topic() ?? Topics::STAFF;
        $publish($this->topic, 'scan', array_filter(['subject' => $this->subject, 'file' => $file]));
    }
}
