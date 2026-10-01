<?php

namespace App\Http\Mail;

use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Mail\Scan\CandidateSubject;
use App\Mail\Scan\OfferSubject;
use App\Mail\Scan\Subject;
use App\Mail\Scan\VehicleSubject;
use App\Mail\Scope;
use App\Offers\Offer;
use App\Park\Actions\FillFromDocs;
use App\Park\Vehicle;
use App\Support\Plural;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «✨ Распознать» — одно окно на парковку и CRM (`x-mail.scan-window` в шелле) для цепочки «Из писем», ТС и
 * предложения (`Scan\Subject`), в три шага одного фрейма.
 * - Файлы: документы и фото плитками; документы и фото с VIN/СТС/ПТС в имени отмечены; `?only=` — один файл
 *   (✨ в шторке документов), окно сразу его читает.
 * - Чтение: отмеченные списком, у каждого — крутится или готов; ставит `Jobs\ScanAttachments`, окно перечитывается
 *   по событию `scan`.
 * - Поля: что нашлось (`ScanFields`), где вариантов несколько — выбор. «Подставить» уводит окно на итог
 *   (`?done=N`, `admin/mail/scan-done`): окно закрывается, то, откуда его открыли, перечитывается на месте.
 *   «Завести» у цепочки парковки уводит в разбор письма.
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
        $done = $this->put($request, $subject);

        return $request->input('then') === 'create'
            ? redirect('/requests/new?candidate='.$candidate->id)
            : $this->done($subject, $done);
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
        $subject = new VehicleSubject($vehicle);

        return $this->done($subject, $this->put($request, $subject));
    }

    public function offerShow(Request $request, Offer $offer)
    {
        return $this->window($request, new OfferSubject($offer));
    }

    public function offerScan(Request $request, Offer $offer)
    {
        return $this->start($request, new OfferSubject($offer));
    }

    public function offerApply(Request $request, Offer $offer)
    {
        $subject = new OfferSubject($offer);

        return $this->done($subject, $this->put($request, $subject));
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
        // Итог «Подставить»: scan_controller закрывает окно, тостит и перечитывает то, что под ним.
        if ($request->has('done')) {
            $count = (int) $request->query('done');

            return view('admin.mail.scan-done', [
                'message' => $count ? 'Подставлено '.$count.' '.Plural::of($count, ['поле', 'поля', 'полей']) : 'Ничего не изменилось',
            ]);
        }
        $files = $subject->files();
        $ids = array_map('intval', (array) $request->query('ids', []));
        $only = $files->firstWhere('id', (int) $request->query('only'));
        $picked = $ids ? $files->whereIn('id', $ids)->values() : collect();
        $texts = $picked->mapWithKeys(fn (Attachment $a) => [$a->id => DocumentText::cached($a)]);
        $reading = ScanAttachments::reading($picked);
        $step = match (true) {
            $picked->isEmpty() || $request->boolean('files') => 'files',
            $reading || $texts->contains(null) => 'reading',
            default => 'fields',
        };

        return view('admin.mail.scan', [
            'subject' => $subject,
            'step' => $step,
            'files' => $files,
            'picked' => $picked,
            'checked' => $only ? [$only->id] : ($ids ?: $this->checked($files)),
            'go' => (bool) $only,
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

    /** Положить выбранное; сколько полей ушло — для итога (машина — одно поле). */
    private function put(Request $request, Subject $subject): int
    {
        $picked = $subject->files()->whereIn('id', array_map('intval', (array) $request->input('ids', [])))->values();
        $rows = $this->rows($subject, $picked, $picked->mapWithKeys(fn (Attachment $a) => [$a->id => DocumentText::cached($a)]));
        $chosen = ScanFields::chosen($rows, (array) $request->input('pick', []));
        $subject->apply($chosen, $request->user());

        return count(array_diff(array_keys($chosen), ['model']));
    }

    /** Итог — редиректом на окно с `?done=N` (POST отвечает редиректом): окно покажет итог (`admin/mail/scan-done`). */
    private function done(Subject $subject, int $count)
    {
        return redirect($subject->url().'?'.http_build_query(['done' => $count]));
    }

    /** Цепочка своего хоста и ещё не заведённая: парковка — `Scope::Park`, CRM — предложения. */
    private function chain(Candidate $candidate): CandidateSubject
    {
        $scope = Surface::current() === Surface::Park ? Scope::Park : Scope::Offers;
        abort_unless($candidate->scope === $scope && $candidate->state === CandidateState::New, 404);

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
        return ScanFields::of($subject->current(), $picked->map(fn (Attachment $a) => [$a, (string) $texts[$a->id], $a->isPhoto()]), $subject->fields());
    }
}
