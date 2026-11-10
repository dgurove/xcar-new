<?php

namespace App\Workflow\Actions;

use App\Media\PhotoIngest;
use App\Workflow\Requirement;
use Illuminate\Http\UploadedFile;

/**
 * Файл к просьбе шага («Приложите…»): снимок — через `PhotoIngest` (HEIC — в JPEG, размер), остальное — как есть с чистым
 * именем. Одна дверь у менеджера (`Cabinet\DealController::upload`) и у админа за него (`Admin\PaymentProofController`,
 * 10.11.2026: менеджер прислал чек в чат, а не на сайт).
 */
final class AttachRequirementFiles
{
    public function __construct(private PhotoIngest $ingest) {}

    public function __invoke(Requirement $requirement, UploadedFile $file): void
    {
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $this->ingest->fromUpload($requirement, 'files', $file);

            return;
        }
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $requirement->addMedia($file)->usingFileName((trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name), '-.') ?: 'dokument').'.'.strtolower($file->getClientOriginalExtension()))->toMediaCollection('files');
    }
}
