<?php

namespace App\Http\Park;

use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Mail\Scan\CandidateSubject;
use App\Mail\Scan\Subject;
use App\Mail\Scan\VehicleSubject;
use App\Mail\Scope;
use App\Park\Actions\FillFromDocs;
use App\Park\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «✨ Распознать» — у цепочки «Из писем» и в деле ТС (`Scan\Subject`): окно (`x-mail.scan-window`) в три шага
 * одного фрейма.
 * - Файлы: документы и фото плитками; документы и фото с VIN/СТС/ПТС в имени отмечены.
 * - Чтение: отмеченные списком, у каждого — крутится или готов; ставит `Jobs\ScanAttachments`, окно перечитывается
 *   по событию `scan`.
 * - Поля: что нашлось (`ScanFields`), где вариантов несколько — выбор; «Подставить» (и у цепочки «Завести»).
 */
final class ScanController
{
    /** Фото с такими словами в имени — снимок таблички VIN, СТС, ПТС: в прогоне 01.10 все VIN с фото пришли с них. */
    private const PHOTO_WITH_TEXT = '/vin|вин|стс|птс|sts|pts|шильд|табличк|номер|госзнак/iu';

    private const PHOTOS_CHECKED = 6;

    public function show(Request $request, Candidate $candidate)
    {
        return $this->window($request, $this->chain($candidate));
    }

    public function scan(Request $request, Candidate $candidate)
    {
        return $this->start($request, $this->chain($candidate));
    }

    public function apply(Request $request, Candidate $candidate)
    {
        $subject = $this->chain($candidate);
        $this->put($request, $subject);

        return $request->input('then') === 'create'
            ? redirect('/requests/new?candidate='.$candidate->id)
            : redirect()->to(url()->previous('/requests/from-mail'));
    }

    public function vehicleShow(Request $request, Vehicle $vehicle)
    {
        return $this->window($request, new VehicleSubject($vehicle));
    }

    public function vehicleScan(Request $request, Vehicle $vehicle)
    {
        return $this->start($request, new VehicleSubject($vehicle));
    }

    public function vehicleApply(Request $request, Vehicle $vehicle)
    {
        $this->put($request, new VehicleSubject($vehicle));

        return redirect('/cars/'.$vehicle->id);
    }

    /** Чип «в документе …» у поля дела: взять значение документа вместо карточки (`FillFromDocs::take`). */
    public function vehicleTake(Request $request, Vehicle $vehicle, FillFromDocs $fill)
    {
        [$field, $value] = array_pad(explode('|', (string) $request->input('take'), 2), 2, '');
        $done = in_array($field, ['vin', 'plate', 'year', 'color', 'value', 'model'], true) && $fill->take($vehicle, $field, $value, $request->user());

        return redirect('/cars/'.$vehicle->id)->with('toast', $done ? 'Взято из документа' : 'Уже не так — обновите');
    }

    private function window(Request $request, Subject $subject)
    {
        $files = $subject->files();
        $ids = array_map('intval', (array) $request->query('ids', []));
        $picked = $ids ? $files->whereIn('id', $ids)->values() : collect();
        $texts = $picked->mapWithKeys(fn (Attachment $a) => [$a->id => DocumentText::cached($a)]);
        $reading = ScanAttachments::reading($picked);
        $step = match (true) {
            $picked->isEmpty() || $request->boolean('files') => 'files',
            $reading || $texts->contains(null) => 'reading',
            default => 'fields',
        };

        return view('park.requests.scan', [
            'subject' => $subject,
            'step' => $step,
            'files' => $files,
            'picked' => $picked,
            'checked' => $ids ?: $this->checked($files),
            'texts' => $texts,
            'reading' => $reading,
            'rows' => $step === 'fields' ? $this->rows($subject, $picked, $texts) : null,
            'ids' => $ids,
            'max' => ScanAttachments::MAX_FILES,
        ]);
    }

    private function start(Request $request, Subject $subject)
    {
        $ids = $subject->files()->whereIn('id', array_map('intval', (array) $request->input('ids', [])))
            ->take(ScanAttachments::MAX_FILES)->pluck('id')->sort()->values()->all();
        if (! $ids) {
            return redirect($subject->url());
        }
        $todo = Attachment::whereIn('id', $ids)->get()->filter(fn (Attachment $a) => DocumentText::cached($a) === null)->pluck('id')->values()->all();
        if ($todo) {
            ScanAttachments::mark($todo);
            ScanAttachments::dispatch($subject->key(), $todo);
        }

        return redirect($subject->url().'?'.http_build_query(['ids' => $ids]));
    }

    private function put(Request $request, Subject $subject): void
    {
        $picked = $subject->files()->whereIn('id', array_map('intval', (array) $request->input('ids', [])))->values();
        $rows = $this->rows($subject, $picked, $picked->mapWithKeys(fn (Attachment $a) => [$a->id => DocumentText::cached($a)]));
        $subject->apply(ScanFields::chosen($rows, (array) $request->input('pick', [])), $request->user());
    }

    private function chain(Candidate $candidate): CandidateSubject
    {
        abort_unless($candidate->scope === Scope::Park && $candidate->state === CandidateState::New, 404);

        return new CandidateSubject($candidate);
    }

    /** Отмечены сразу: документы и фото таблички VIN, СТС, ПТС (по имени), не больше лимита пачки. @return list<int> */
    private function checked(Collection $files): array
    {
        $docs = $files->reject->isPhoto()->pluck('id');
        $photos = $files->filter(fn (Attachment $a) => $a->isPhoto() && preg_match(self::PHOTO_WITH_TEXT, (string) $a->filename))->take(self::PHOTOS_CHECKED)->pluck('id');

        return $docs->concat($photos)->take(ScanAttachments::MAX_FILES)->values()->all();
    }

    /** @param Collection<int, Attachment> $picked @param Collection<int, ?string> $texts */
    private function rows(Subject $subject, Collection $picked, Collection $texts): array
    {
        return ScanFields::of($subject->current(), $picked->map(fn (Attachment $a) => [$a, (string) $texts[$a->id], $a->isPhoto()]));
    }
}
