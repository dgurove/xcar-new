<?php

namespace App\Workflow\Actions;

use App\Media\PhotoIngest;
use App\Offers\Documents\PaymentProofPdf;
use App\Support\Pdf;
use App\Support\ScanImage;
use App\Workflow\Asks;
use App\Workflow\Requirement;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Файл к просьбе шага («Приложите…»): снимок — через `PhotoIngest` (HEIC — в JPEG, размер), остальное — как есть с чистым
 * именем. Одна дверь у менеджера (`Cabinet\DealController::upload`) и у админа за него (`Admin\PaymentProofController`,
 * 10.11.2026: менеджер прислал чек в чат, а не на сайт).
 *
 * Платёжка на шаге оплаты — сразу PDF (16.11.2026, владелец: «почему-то в webp и jpeg, нет — как только её прикладывают, она
 * должна конвертироваться в пдф и сразу считаться платёжкой»): снимок — листом A4 тем же видом, что документ для страховой
 * (`PaymentProofPdf`), файл «Платёжное поручение <марка модель>.pdf».
 */
final class AttachRequirementFiles
{
    public function __construct(private PhotoIngest $ingest) {}

    /**
     * @param  bool  $named  готовый документ «Приложить» (`PhotosPdf::upload`, 24.11.2026): лежит под названием, что дал человек
     *                       («Платёжное поручение Renault Duster.pdf»), без своего имени шага и без пересборки
     */
    public function __invoke(Requirement $requirement, UploadedFile $file, bool $named = false): void
    {
        if ($named) {
            $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ext = strtolower($file->getClientOriginalExtension());
            $taken = $requirement->getMedia('files')->pluck('file_name')->all();
            for ($n = 1; in_array($name = $title.($n > 1 ? ' '.$n : '').'.'.$ext, $taken, true); $n++);
            $requirement->addMedia($file)->usingFileName($name)->usingName($title)->withCustomProperties(['title' => $title])->toMediaCollection('files');

            return;
        }
        $image = str_starts_with((string) $file->getMimeType(), 'image/') || strtolower($file->getClientOriginalExtension()) === 'heic';
        if ($image && self::paysOn($requirement)) {
            $this->imageAsPdf($requirement, $file);

            return;
        }
        if ($image) {
            $this->ingest->fromUpload($requirement, 'files', $file);

            return;
        }
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $name = self::paysOn($requirement) ? self::proofName($requirement) : (self::clean($name) ?: 'dokument').'.'.strtolower($file->getClientOriginalExtension());
        $media = $requirement->addMedia($file)->usingFileName($name);
        if (self::paysOn($requirement)) {
            $media->withCustomProperties(['title' => pathinfo($name, PATHINFO_FILENAME)]);
        }
        $media->toMediaCollection('files');
    }

    /** Просьба шага оплаты приложить документ — платёжка. */
    public static function paysOn(Requirement $requirement): bool
    {
        return $requirement->asks === Asks::Document && (bool) $requirement->stage?->isPayStep();
    }

    /**
     * Снимок → PDF: `PhotoIngest` поворачивает и перекодирует (HEIC тоже) во временную коллекцию `scan` — в `files` кадр не
     * ложится; тот же снимок второй раз не ложится (отпечаток исходника у PDF).
     */
    private function imageAsPdf(Requirement $requirement, UploadedFile $file): void
    {
        $sha = hash_file('sha256', $file->getRealPath());
        if ($requirement->getMedia('files')->contains(fn ($m) => $m->getCustomProperty('source_sha') === $sha)) {
            return;
        }
        $shot = $this->ingest->fromUpload($requirement, 'scan', $file);
        try {
            $pages = ScanImage::pages($shot);
        } finally {
            $shot->delete();
            $requirement->unsetRelation('media');
        }
        if ($pages === []) {
            throw ValidationException::withMessages(['file' => 'Не получилось прочитать снимок, приложите другой файл']);
        }
        $pdf = Pdf::render('park.report.scan', ['pages' => array_map(fn ($j) => 'data:image/jpeg;base64,'.base64_encode($j), $pages)]);
        $requirement->addMediaFromString($pdf)->usingFileName(self::proofName($requirement))
            ->withCustomProperties(['source_sha' => $sha, 'source' => $file->getClientOriginalName(), 'title' => pathinfo(self::proofName($requirement), PATHINFO_FILENAME)])
            ->toMediaCollection('files');
    }

    /** «Платёжное поручение Renault Duster.pdf» (24.11.2026, без номера убытка), следующие — «… 2.pdf». */
    private static function proofName(Requirement $requirement): string
    {
        $base = pathinfo($requirement->deal ? PaymentProofPdf::fileName($requirement->deal) : 'Платёжное поручение', PATHINFO_FILENAME);
        $taken = $requirement->getMedia('files')->pluck('file_name')->all();
        for ($n = 1; in_array($name = $base.($n > 1 ? ' '.$n : '').'.pdf', $taken, true); $n++);

        return $name;
    }

    private static function clean(string $name): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name), '-.');
    }
}
