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
use Illuminate\Http\Request;
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
    public function store(Request $request, Vehicle $vehicle, PhotoIngest $ingest, SendIntakeReport $send)
    {
        $kinds = array_map(fn (ReportDoc $k) => $k->value, IntakeReport::kinds($vehicle->loadMissing('vendor')));
        $data = $request->validate([
            'to' => ['required', 'string', 'max:500'], 'cc' => ['nullable', 'string', 'max:500'], 'text' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array'], 'photos.*' => ['integer'],
            'docs' => ['nullable', 'array'], 'docs.*' => ['array'], 'docs.*.*' => ['integer'],
            'new_photos' => ['nullable', 'array', 'max:30'], 'new_photos.*' => ['file', 'max:65536'],
            'scans' => ['nullable', 'array'], 'scans.*' => ['array', 'max:12'], 'scans.*.*' => ['file', 'max:65536'],
        ], ['to.required' => 'Кому отправить']);
        abort_unless($vehicle->accepted_at, 404);

        $photoIds = array_map('intval', $data['photos'] ?? []);
        foreach ($request->file('new_photos', []) as $file) {
            $photoIds[] = $ingest->fromUpload($vehicle, 'photos', $file, ['stage' => 'intake', 'source' => 'app', 'hidden' => true])->id;
        }
        $docIds = array_map(fn ($ids) => array_map('intval', $ids), array_intersect_key($data['docs'] ?? [], array_flip($kinds)));
        foreach (array_intersect_key($request->file('scans', []), array_flip($kinds)) as $kind => $files) {
            foreach ($this->scans($vehicle, ReportDoc::from($kind), $files) as $id) {
                $docIds[$kind][] = $id;
            }
        }
        if (! $photoIds && ! array_filter($docIds)) {
            return back()->withErrors(['report' => 'Отметьте фото или добавьте документ']);
        }

        $report = IntakeReport::for($vehicle->fresh());
        $sent = $send($report, $request->user(), $photoIds, $docIds, (string) ($data['text'] ?? ''), $data['to'], (string) ($data['cc'] ?? ''));
        $toast = count($sent) > 1 ? 'Отчёт ушёл '.count($sent).' письмами' : 'Отчёт в очереди';
        // Из «Заявок» (сразу после «Принять») — к следующей заявке, как после приёма без отчёта.
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('toast', $toast)->with($request->boolean('advance') ? ['detail-advance' => true] : []);
        }

        return redirect("/cars/{$vehicle->id}")->with('toast', $toast);
    }

    /** «Уже отправили»: отчёт ушёл не через нас (личная почта) — шаг сделан без письма. */
    public function sent(Request $request, Vehicle $vehicle, MarkReportSent $mark)
    {
        if (! MarkReportSent::done($vehicle)) {
            $mark($vehicle, $request->user(), 'Акт приёма', now(), null, ['manual' => true]);
        }
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('toast', 'Отчёт отмечен отправленным')->with($request->boolean('advance') ? ['detail-advance' => true] : []);
        }

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Отчёт отмечен отправленным');
    }

    /**
     * Снимки документа — одним PDF (страница на снимок), PDF и прочие файлы — как есть; всё в документы ТС с видом.
     *
     * @param  list<UploadedFile>  $files
     * @return list<int>
     */
    private function scans(Vehicle $vehicle, ReportDoc $kind, array $files): array
    {
        $images = array_values(array_filter($files, fn (UploadedFile $f) => str_starts_with((string) $f->getMimeType(), 'image/')));
        $ids = [];
        foreach (array_filter($files, fn (UploadedFile $f) => ! in_array($f, $images, true)) as $file) {
            $ids[] = $vehicle->addMedia($file)->usingFileName($kind->word().'-'.uniqid().'.'.($file->getClientOriginalExtension() ?: 'pdf'))
                ->withCustomProperties(['report_doc' => $kind->value, 'source' => 'app'])->toMediaCollection('papers')->id;
        }
        if ($images) {
            $pages = [];
            foreach ($images as $image) {
                $tmp = tempnam(sys_get_temp_dir(), 'scan').'.jpg';
                try {
                    Image::useImageDriver('gd')->loadFile($image->getRealPath())->fit(Fit::Max, 1800, 1800)->quality(80)->save($tmp);
                    $pages[] = 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($tmp));
                } finally {
                    @unlink($tmp);
                }
            }
            $pdf = Pdf::render('park.report.scan', ['pages' => $pages]);
            $ids[] = $vehicle->addMediaFromString($pdf)->usingFileName($kind->word().'-'.uniqid().'.pdf')
                ->withCustomProperties(['report_doc' => $kind->value, 'source' => 'app'])->toMediaCollection('papers')->id;
        }

        return $ids;
    }
}
