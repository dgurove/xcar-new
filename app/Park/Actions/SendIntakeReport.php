<?php

namespace App\Park\Actions;

use App\Mail\Actions\StoreOutboxFile;
use App\Mail\Composer;
use App\Mail\Message;
use App\Mail\Parts;
use App\Mail\Template;
use App\Park\IntakeReport;
use App\Park\Report;
use App\Park\ReportDoc;
use App\Park\Vehicle;
use App\Support\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Письма отчёта о приёме (`Park\Report`) — в очереди (`Jobs\SendIntakeReport`), а не в запросе формы: там все снимки
 * одним запросом упирались в 64 МБ и 30 с (29.10.2026, «ошибки при отправлении»). Ответ в ветке заявки, фраза, отмеченные
 * кадры JPEG-ами 1600 и сканы документов с именами, как их называли руками. Снимки «Снять» одного выбора (`scan`) —
 * одним PDF, он встаёт к ТС вместо снимков. Не влезает в 18 МБ — частями «ч.1, ч.2», сканы — в первой.
 * Файла, что отметили, у ТС нет или он не читается — ошибка, письмо без него не уходит. Шаг «Отчёт» ставит не отправка, а
 * ушедшее письмо (`Listeners\ReportDelivery`).
 */
final class SendIntakeReport
{
    /** @var array<int, array{0: Media, 1: string}> кадр или скан → отпечаток ушедших байт */
    private array $sha = [];

    public function __construct(private Composer $composer, private StoreOutboxFile $outbox) {}

    /** @return list<Message> */
    public function __invoke(Report $report): array
    {
        $this->sha = [];
        $v = $report->vehicle()->with(['vendor', 'media', 'requests'])->firstOrFail();
        $intake = IntakeReport::for($v, $report->parent);
        if (! $intake) {
            throw new RuntimeException('Нет ящика парковки для отправки');
        }
        $media = $v->getMedia('photos')->merge($v->papers())->keyBy('id');
        $pick = fn (array $ids, string $collection) => collect($ids)->map(function ($id) use ($media, $collection) {
            $m = $media->get((int) $id);
            if (! $m || $m->collection_name !== $collection) {
                throw new RuntimeException('Файла №'.$id.' у ТС больше нет — откройте отчёт заново');
            }

            return $m;
        })->values();

        // Сканы — первыми, по видам в порядке вендора, «Другой» последним; несколько файлов вида — «акт 1», «акт 2».
        // Снимки встают PDF-ами, но удаляются, только когда отчёт уже помнит PDF: упади сборка посреди — отчёт ссылался бы на
        // удалённые снимки, и «Повторить» не помогал бы никогда; не собрались все — новые PDF убираются, снимки на месте.
        $files = [];
        $docs = $report->docs ?? [];
        $lists = [];
        $made = collect();
        $pages = collect();
        try {
            foreach ([...IntakeReport::kinds($v), ReportDoc::Other] as $kind) {
                $lists[$kind->value] = $this->paged($v, $kind, $pick($docs[$kind->value] ?? [], 'papers'), $made, $pages);
            }
        } catch (Throwable $e) {
            $made->each(fn (Media $m) => $m->delete());
            throw $e;
        }
        $report->update(['docs' => array_filter(array_map(fn ($list) => $list->pluck('id')->all(), $lists))]);
        $pages->each(fn (Media $m) => $m->delete());
        foreach ($lists as $key => $list) {
            $kind = ReportDoc::from($key);
            foreach ($list as $i => $m) {
                $contents = $this->read($m);
                $this->sha[$m->id] = [$m, hash('sha256', $contents)];
                $ext = strtolower(pathinfo($m->file_name, PATHINFO_EXTENSION)) ?: 'pdf';
                $files[] = $this->outbox->put($intake->fileName($kind, $i, $list->count(), $ext, $m->file_name), $contents);
            }
        }
        $docsCount = count($files);
        foreach ($pick($report->photos ?? [], 'photos') as $i => $m) {
            $files[] = $this->photo($m, $i + 1);
        }
        if (! $files) {
            throw new RuntimeException('Нечего отправлять: ни фото, ни документов');
        }

        // Части по объёму: сканы стоят первыми и уходят в первой.
        $disk = Storage::disk(Parts::CACHE_DISK);
        $parts = [[]];
        $size = 0;
        foreach ($files as $path) {
            $bytes = $disk->size($path);
            if ($size > 0 && $size + $bytes > IntakeReport::$partBytes) {
                $parts[] = [];
                $size = 0;
            }
            $parts[array_key_last($parts)][] = $path;
            $size += $bytes;
        }

        $parent = $intake->parent;
        $base = $parent ? $this->composer->reply($parent) : $this->composer->fresh($intake->account);
        $subject = $parent ? $base['subject'] : 'Приём ТС '.$v->titleWithYear().($v->ref ? ' '.$v->ref : '');
        $body = Template::html(trim((string) $report->text) !== '' ? trim((string) $report->text) : IntakeReport::text($docsCount > 0)).$base['body'];

        // Письма, отпечатки и номера писем у отчёта — одной транзакцией: упади вторая часть — нет и первой (иначе она висела бы
        // «в очереди» без отправки, а «Повторить» собирал бы второй комплект). В отправку — после записи: `ReportDelivery`
        // ищет отчёт по письму.
        $sent = DB::transaction(function () use ($parts, $intake, $report, $subject, $body, $base, $parent, $v) {
            $sent = [];
            foreach ($parts as $n => $chunk) {
                $sent[] = $this->composer->create($intake->account, [
                    'to' => $report->to, 'cc' => (string) $report->cc,
                    'subject' => $subject.(count($parts) > 1 ? ' ч.'.($n + 1) : ''),
                    'body' => $body,
                    'quote' => $base['quote'] ?? null,
                    'files' => $chunk,
                ], $parent, $report->user, dispatch: false);
            }
            // Отпечатки отправленного: из «Отправленных» эти же кадры и сканы второй раз к ТС не лягут.
            foreach ($this->sha as [$m, $sha]) {
                $m->setCustomProperty('sent_sha', $sha)->save();
            }
            foreach ($sent as $message) {
                if ($message->thread && ! $message->thread->vehicle_id) {
                    $message->thread->update(['vehicle_id' => $v->id]);
                }
            }
            $report->update(['messages' => array_map(fn (Message $m) => $m->id, $sent), 'error' => null]);

            return $sent;
        });
        $this->sha = [];
        foreach ($sent as $message) {
            $this->composer->dispatch($message);
        }

        return $sent;
    }

    /**
     * Снимки «Снять» одного выбора (`scan`) — страницы одного документа: одним PDF, он встаёт к ТС вместо снимков (и в
     * следующий отчёт уйдёт уже им). Картинка «Файлом» — своим PDF так же. Остальное — как есть. Новые PDF — в `$made`,
     * снимки, что они заменили, — в `$pages`: удаляет их вызвавший, когда отчёт запомнит PDF.
     *
     * @param  Collection<int, Media>  $docs
     * @param  Collection<int, Media>  $made
     * @param  Collection<int, Media>  $pages
     * @return Collection<int, Media>
     */
    private function paged(Vehicle $v, ReportDoc $kind, Collection $docs, Collection $made, Collection $pages): Collection
    {
        $out = collect();
        $groups = $docs->groupBy(fn (Media $m) => str_starts_with((string) $m->mime_type, 'image/') ? ($m->getCustomProperty('scan') ?: 'one-'.$m->id) : 'file-'.$m->id);
        foreach ($groups as $key => $group) {
            if (str_starts_with((string) $key, 'file-')) {
                $out->push($group->first());

                continue;
            }
            // dompdf молча рисует пустую страницу вместо картинки, которую не прочёл (HEIC, битый файл): такой скан не уходит.
            foreach ($group as $m) {
                $type = @getimagesize($m->getPath())[2] ?? null;
                if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
                    throw new RuntimeException('Снимок «'.$m->file_name.'» не читается — снимите документ заново');
                }
            }
            $pdf = Pdf::render('park.report.scan', ['pages' => $group->sortBy(fn (Media $m) => [(int) $m->getCustomProperty('page'), $m->id])->map(fn (Media $m) => $m->getPath())->values()->all()],
                chroot: array_values(array_unique($group->map(fn (Media $m) => dirname($m->getPath()))->all())));
            $doc = $v->addMediaFromString($pdf)->usingFileName($kind->value.'-'.uniqid().'.pdf')
                ->withCustomProperties(['report_doc' => $kind->value, 'source' => 'app'])->toMediaCollection('papers');
            $made->push($doc);
            $pages->push(...$group->all());
            $out->push($doc);
        }

        return $out;
    }

    private function read(Media $m): string
    {
        $contents = is_file($m->getPath()) ? file_get_contents($m->getPath()) : false;
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Файл «'.$m->file_name.'» не читается с диска');
        }

        return $contents;
    }

    /** Кадр во вложение — JPEG 1600: почтовые программы страховых webp не показывают. */
    private function photo(Media $m, int $n): string
    {
        $this->read($m);
        $tmp = tempnam(sys_get_temp_dir(), 'rep').'.jpg';
        try {
            Image::useImageDriver('gd')->loadFile($m->getPath())->fit(Fit::Max, 1600, 1600)->quality(82)->save($tmp);
            $contents = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        if ($contents === '') {
            throw new RuntimeException('Кадр «'.$m->file_name.'» не пережался в JPEG');
        }
        $this->sha[$m->id] = [$m, hash('sha256', $contents)];

        return $this->outbox->put('priem-'.$n.'.jpg', $contents);
    }
}
