<?php

namespace App\Park\Actions;

use App\Mail\Actions\StoreOutboxFile;
use App\Mail\Composer;
use App\Mail\Message;
use App\Mail\Parts;
use App\Mail\Template;
use App\Park\IntakeReport;
use App\Park\ReportDoc;
use App\Users\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Отчёт о приёме письмом (форма `x-park.report-form`): ответ в ветке заявки, фраза, отмеченные фото приёма JPEG-ами 1600
 * и сканы документов с именами, как их называли руками. Не влезает в 18 МБ — частями «ч.1, ч.2», сканы — в первой.
 * Ушло — шаг «Отчёт» сделан (`MarkReportSent`).
 */
final class SendIntakeReport
{
    /** @var array<int, array{0: Media, 1: string}> кадр или скан → отпечаток ушедших байт */
    private array $sha = [];

    public function __construct(private Composer $composer, private StoreOutboxFile $outbox, private MarkReportSent $mark) {}

    /**
     * @param  list<int>  $photoIds  кадры ТС, что уходят
     * @param  array<string, list<int>>  $docIds  вид документа → документы ТС (`papers`)
     * @return list<Message>
     */
    public function __invoke(IntakeReport $report, User $by, array $photoIds, array $docIds, string $text, string $to, string $cc = ''): array
    {
        $v = $report->vehicle;
        $media = $v->getMedia('photos')->merge($v->papers())->keyBy('id');

        // Сканы — первыми, по видам в порядке вендора; несколько файлов вида — «акт 1», «акт 2».
        $files = [];
        foreach (IntakeReport::kinds($v) as $kind) {
            $docs = collect($docIds[$kind->value] ?? [])->map(fn ($id) => $media->get((int) $id))->filter(fn ($m) => $m && $m->collection_name === 'papers')->values();
            foreach ($docs as $i => $m) {
                $ext = strtolower(pathinfo($m->file_name, PATHINFO_EXTENSION)) ?: 'pdf';
                $contents = (string) file_get_contents($m->getPath());
                $this->sha[$m->id] = [$m, hash('sha256', $contents)];
                $files[] = $this->outbox->put($report->fileName($kind, $i, $docs->count(), $ext), $contents);
            }
        }
        $docsCount = count($files);
        foreach (array_values(array_filter(array_map(fn ($id) => $media->get((int) $id), $photoIds), fn ($m) => $m && $m->collection_name === 'photos')) as $i => $m) {
            $files[] = $this->photo($m, $i + 1);
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

        $parent = $report->parent;
        $base = $parent ? $this->composer->reply($parent) : $this->composer->fresh($report->account);
        $subject = $parent ? $base['subject'] : 'Приём ТС '.$v->titleWithYear().($v->ref ? ' '.$v->ref : '');
        $body = Template::html(trim($text) !== '' ? trim($text) : IntakeReport::text($docsCount > 0)).$base['body'];

        $sent = [];
        foreach ($parts as $n => $chunk) {
            $sent[] = $this->composer->create($report->account, [
                'to' => $to, 'cc' => $cc,
                'subject' => $subject.(count($parts) > 1 ? ' ч.'.($n + 1) : ''),
                'body' => $body,
                'files' => $chunk,
            ], $parent, $by);
        }
        // Отпечатки отправленного — когда письма уже заведены: из «Отправленных» эти же кадры и сканы второй раз к ТС не лягут.
        foreach ($this->sha as [$m, $sha]) {
            $m->setCustomProperty('sent_sha', $sha)->save();
        }
        $this->sha = [];
        foreach ($sent as $message) {
            if ($message->thread && ! $message->thread->vehicle_id) {
                $message->thread->update(['vehicle_id' => $v->id]);
            }
        }
        ($this->mark)($v, $by, 'Акт приёма', now(), $sent[0]->thread_id, ['message' => $sent[0]->id, 'photos' => count($files) - $docsCount, 'docs' => $docsCount]);

        return $sent;
    }

    /** Кадр во вложение — JPEG 1600: почтовые программы страховых webp не показывают. */
    private function photo(Media $m, int $n): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rep').'.jpg';
        try {
            Image::useImageDriver('gd')->loadFile($m->getPath())->fit(Fit::Max, 1600, 1600)->quality(82)->save($tmp);
            $contents = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        $this->sha[$m->id] = [$m, hash('sha256', $contents)];

        return $this->outbox->put('priem-'.$n.'.jpg', $contents);
    }
}
