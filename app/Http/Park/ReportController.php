<?php

namespace App\Http\Park;

use App\Media\PhotoIngest;
use App\Park\Actions\MarkReportSent;
use App\Park\Actions\SendIntakeReport;
use App\Park\IntakeReport;
use App\Park\ReportDoc;
use App\Park\Vehicle;
use App\Support\Detail;
use App\Support\Pdf;
use App\Mail\Composer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * Отчёт о приёме вендору (12.10.2026): форма `x-park.report-form` в карточке заявки после «Принять», в карточке
 * «Наличия» и в деле. Новые фото — в «Фото при приёме», снимки документа — одним PDF в документы ТС с видом; дальше
 * письмо (`SendIntakeReport`). «Уже отправили» — отчёт ушёл с личной почты.
 */
class ReportController
{
    public function store(Request $request, Vehicle $vehicle, PhotoIngest $ingest, SendIntakeReport $send, Composer $composer)
    {
        $kinds = array_map(fn (ReportDoc $k) => $k->value, IntakeReport::kinds($vehicle->loadMissing('vendor')));
        $data = $request->validate([
            'to' => ['required', 'string', 'max:500'], 'cc' => ['nullable', 'string', 'max:500'], 'text' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array'], 'photos.*' => ['integer'],
            'docs' => ['nullable', 'array'], 'docs.*' => ['array'], 'docs.*.*' => ['integer'],
            'new_photos' => ['nullable', 'array', 'max:30'], 'new_photos.*' => ['file', 'max:65536'],
            'scans' => ['nullable', 'array'], 'scans.*' => ['array', 'max:12'], 'scans.*.*' => ['file', 'max:65536'],
            'files' => ['nullable', 'array'], 'files.*' => ['array', 'max:12'], 'files.*.*' => ['file', 'max:65536'],
        ], ['to.required' => 'Кому отправить']);
        abort_unless($vehicle->accepted_at, 404);
        // Всё проверяется до того, как что-то ляжет к ТС: иначе повтор после ошибки плодил бы вторые сканы.
        if (! $composer->emails($data['to'])) {
            return back()->withInput()->withErrors(['to' => 'Нужен адрес почты']);
        }
        $photoIds = array_map('intval', $data['photos'] ?? []);
        $docIds = array_map(fn ($ids) => array_map('intval', $ids), array_intersect_key($data['docs'] ?? [], array_flip($kinds)));
        $scans = array_intersect_key($request->file('scans', []), array_flip($kinds));
        $files = array_intersect_key($request->file('files', []), array_flip($kinds));
        if (! $photoIds && ! $request->file('new_photos') && ! array_filter($docIds) && ! array_filter($scans) && ! array_filter($files)) {
            return back()->withErrors(['report' => 'Отметьте фото или добавьте документ']);
        }

        // Один отчёт на ТС: двойное нажатие, повтор формы или старая вкладка второй раз письмо не шлют.
        $lock = Cache::lock('park-report:'.$vehicle->id, 120);
        if (! $lock->get()) {
            return $this->back($request, 'Отчёт уже отправляется');
        }
        try {
            if (MarkReportSent::done($vehicle)) {
                return $this->back($request, 'Отчёт уже отправлен');
            }
            foreach ($request->file('new_photos', []) as $file) {
                $photoIds[] = $ingest->fromUpload($vehicle, 'photos', $file, ['stage' => 'intake', 'source' => 'app', 'hidden' => true])->id;
            }
            // «Снять» — страницы одного документа, одним PDF; «Файл» — каждый файл своим документом.
            foreach ($scans as $kind => $list) {
                $docIds[$kind][] = $this->pdf($vehicle, ReportDoc::from($kind), $list);
            }
            foreach ($files as $kind => $list) {
                foreach ($list as $file) {
                    $docIds[$kind][] = str_starts_with((string) $file->getMimeType(), 'image/')
                        ? $this->pdf($vehicle, ReportDoc::from($kind), [$file])
                        : $vehicle->addMedia($file)->usingFileName($kind.'-'.uniqid().'.'.($file->getClientOriginalExtension() ?: 'pdf'))
                            ->withCustomProperties(['report_doc' => $kind, 'source' => 'app'])->toMediaCollection('papers')->id;
                }
            }
            $report = IntakeReport::for($vehicle->fresh());
            abort_unless($report, 422, 'Нет ящика парковки для отправки');
            $sent = $send($report, $request->user(), $photoIds, $docIds, (string) ($data['text'] ?? ''), $data['to'], (string) ($data['cc'] ?? ''));
        } finally {
            $lock->release();
        }

        return $this->back($request, count($sent) > 1 ? 'Отчёт ушёл '.count($sent).' письмами' : 'Отчёт в очереди');
    }

    /** Из «Заявок» (сразу после «Принять») — к следующей заявке; из «Наличия» — та же карточка; из дела — дело. */
    private function back(Request $request, string $toast)
    {
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('toast', $toast)->with($request->boolean('advance') ? ['detail-advance' => true] : []);
        }

        return redirect('/cars/'.$request->route('vehicle')->id)->with('toast', $toast);
    }

    /** «Уже отправили»: отчёт ушёл не через нас (личная почта) — шаг сделан без письма. */
    public function sent(Request $request, Vehicle $vehicle, MarkReportSent $mark)
    {
        if (! MarkReportSent::done($vehicle)) {
            $mark($vehicle, $request->user(), 'Акт приёма', now(), null, ['manual' => true]);
        }
        return $this->back($request, 'Отчёт отмечен отправленным');
    }

    /**
     * Страницы документа одним PDF в документы ТС с видом. Кадры — файлами во временной папке, а не base64 в HTML: так
     * dompdf не держит десятки мегабайт строкой.
     *
     * @param  list<UploadedFile>  $images
     */
    private function pdf(Vehicle $vehicle, ReportDoc $kind, array $images): int
    {
        $dir = sys_get_temp_dir().'/scan-'.uniqid();
        @mkdir($dir);
        try {
            $pages = [];
            foreach (array_values($images) as $i => $image) {
                if (! str_starts_with((string) $image->getMimeType(), 'image/')) {
                    continue;
                }
                $path = "{$dir}/{$i}.jpg";
                Image::useImageDriver('gd')->loadFile($image->getRealPath())->fit(Fit::Max, 1400, 1400)->quality(75)->save($path);
                $pages[] = $path;
            }
            $pdf = Pdf::render('park.report.scan', ['pages' => $pages], chroot: [$dir]);
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }

        return $vehicle->addMediaFromString($pdf)->usingFileName($kind->value.'-'.uniqid().'.pdf')
            ->withCustomProperties(['report_doc' => $kind->value, 'source' => 'app'])->toMediaCollection('papers')->id;
    }
}
