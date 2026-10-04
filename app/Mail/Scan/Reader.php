<?php

namespace App\Mail\Scan;

use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Читалка «Завести» (`x-mail.reader`) — блок «Документы» разбора письма парковки и редактора предложения: документы
 * предмета читаются сами по порядку (`Files::rank`), поля формы заполняются по мере чтения (`reader_controller`).
 * Здесь — что читать, ход чтения и найденное; читает `Jobs\ScanAttachments`, в задаче — только непрочитанное.
 */
final class Reader
{
    /**
     * Файлы читалки по порядку чтения: документы предмета, за ними — дописанное искрой шторки документов (фото тоже),
     * в том порядке, в каком дописывали. @return Collection<int, ScanFile>
     */
    public static function files(Subject $subject): Collection
    {
        $files = $subject->files();
        $more = self::more($subject);

        return Files::rank($files)->take(ScanAttachments::MAX_FILES)
            ->concat($files->filter(fn (ScanFile $f) => in_array($f->scanId(), $more, true))->sortBy(fn (ScanFile $f) => array_search($f->scanId(), $more, true)))
            ->unique(fn (ScanFile $f) => $f->scanId())
            ->values();
    }

    /**
     * «Читать»: без номеров — всё непрочитанное по порядку (автозапуск, «Дочитать», «Повторить»), с номерами —
     * дописать эти файлы в конец (искра шторки документов). Прочитанное и читаемое в задачу не идёт.
     *
     * @param  list<string>  $ids
     */
    public static function read(Subject $subject, array $ids = []): array
    {
        if ($ids) {
            $ids = array_values(array_intersect($ids, $subject->files()->map->scanId()->all()));
            Cache::put(self::moreKey($subject), array_values(array_unique([...self::more($subject), ...$ids])), 86400);
        }
        $files = self::files($subject)->filter(fn (ScanFile $f) => ! $ids || in_array($f->scanId(), $ids, true));
        $busy = ScanAttachments::reading($files);
        ScanAttachments::enqueue($subject, $files->filter(fn (ScanFile $f) => DocumentText::cached($f) === null && ! in_array($f->scanId(), $busy, true))->map->scanId()->values()->all());

        return self::live($subject);
    }

    public static function stop(Subject $subject): array
    {
        ScanAttachments::stop($subject);

        return self::live($subject);
    }

    /**
     * Ход чтения и найденное:
     * - `state` — `idle` (не начинали), `queued` (ждёт очереди за чужим чтением), `reading`, `stopped` (остановили или
     *   что-то не прочлось), `done`;
     * - `html` — строка хода и документы строками (`admin/mail/reader-sheets`);
     * - `marks` — ход по отпечатку файла: его ставят на строки «Документов» редактора (`data-scan-key`);
     * - `values` — найденное в прочитанном, в именах полей формы (`FormValues`): с формой сравнивает читалка.
     *
     * `$open` — первый PDF открывается в шторке сам (разбор письма: начальник заполняет, глядя в скан).
     *
     * @return array{state: string, marks: array<string, string>, html: string, values: array}
     */
    public static function live(Subject $subject, bool $open = false): array
    {
        $files = self::files($subject);
        $texts = $files->mapWithKeys(fn (ScanFile $f) => [$f->scanId() => DocumentText::cached($f)]);
        $marked = ScanAttachments::reading($files);
        // «Ждёт очереди» — только когда работник читает чужое; свободен — задача начнётся через пару секунд, это уже чтение.
        $running = $marked && (ScanAttachments::running($subject) || ! ScanAttachments::workerBusy());
        $states = $files->mapWithKeys(fn (ScanFile $f) => [$f->scanId() => match (true) {
            $texts[$f->scanId()] !== null => trim($texts[$f->scanId()]) !== '' ? 'done' : 'empty',
            in_array($f->scanId(), $marked, true) => $running && $f->scanId() === $marked[0] ? 'busy' : 'wait',
            ScanAttachments::lost($f->scanId()) => 'lost',
            default => 'idle',
        }]);
        $left = $states->filter(fn ($s) => in_array($s, ['idle', 'wait', 'busy'], true))->count();
        $state = match (true) {
            (bool) $marked => $running ? 'reading' : 'queued',
            ! $left => $states->contains('lost') ? 'stopped' : 'done',
            ScanAttachments::stopped($subject) => 'stopped',
            default => 'idle',
        };
        $read = $files->filter(fn (ScanFile $f) => $texts[$f->scanId()] !== null);
        $found = ScanFields::found($subject->current(), $read->map(fn (ScanFile $f) => [$f, (string) $texts[$f->scanId()], $f->isPhoto()]));

        return [
            'state' => $state,
            'marks' => $files->mapWithKeys(fn (ScanFile $f) => [self::mark($f) => $states[$f->scanId()]])->all(),
            'html' => view('admin.mail.reader-sheets', ['subject' => $subject, 'files' => $files, 'states' => $states, 'state' => $state, 'auto' => $open])->render(),
            'values' => FormValues::of($found, $subject->fields()),
        ];
    }

    /**
     * Метка файла для хода чтения на строке документа (`data-scan-key`): отпечаток содержимого — он один у вложения
     * письма и у того же файла в документах предложения или ТС; без отпечатка — номер.
     */
    public static function mark(ScanFile $file): string
    {
        return $file->scanSha() ?: 'id-'.$file->scanId();
    }

    /** @return list<string> */
    private static function more(Subject $subject): array
    {
        return (array) Cache::get(self::moreKey($subject), []);
    }

    private static function moreKey(Subject $subject): string
    {
        return "scan:more:{$subject->key()}";
    }
}
