<?php

namespace App\Http\Mail;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Mail\Scan\CandidateSubject;
use App\Mail\Scan\OfferSubject;
use App\Mail\Scan\Reader;
use App\Mail\Scan\ScanFile;
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
 * предложения (`Scan\Subject`), в три шага одного фрейма. Файлы — `Scan\ScanFile` по номеру `scanId`: `812` —
 * вложение письма, `m45` — документ предложения.
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
        return $this->window($request, OfferSubject::for($offer, $request->user()));
    }

    public function offerScan(Request $request, Offer $offer)
    {
        return $this->start($request, OfferSubject::for($offer, $request->user()));
    }

    public function offerApply(Request $request, Offer $offer)
    {
        $subject = OfferSubject::for($offer, $request->user());

        return $this->done($subject, $this->put($request, $subject));
    }

    /** Читалка «Завести» в разборе письма парковки (`x-mail.reader-body`, `Scan\Reader`): ход чтения и найденное — JSON. */
    public function readerLive(Candidate $candidate)
    {
        return response()->json(Reader::live($this->chain($candidate)));
    }

    public function readerRead(Request $request, Candidate $candidate)
    {
        return response()->json(Reader::read($this->chain($candidate), $this->ids($request->input('ids', []))));
    }

    public function readerStop(Candidate $candidate)
    {
        return response()->json(Reader::stop($this->chain($candidate)));
    }

    /** Читалка в блоке «Документы» редактора предложения. */
    public function offerLive(Request $request, Offer $offer)
    {
        return response()->json(Reader::live(OfferSubject::for($offer, $request->user())));
    }

    public function offerRead(Request $request, Offer $offer)
    {
        return response()->json(Reader::read(OfferSubject::for($offer, $request->user()), $this->ids($request->input('ids', []))));
    }

    public function offerStop(Request $request, Offer $offer)
    {
        return response()->json(Reader::stop(OfferSubject::for($offer, $request->user())));
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
        $ids = $this->ids($request->query('ids', []));
        $only = $files->first(fn (ScanFile $f) => $f->scanId() === (string) $request->query('only'));
        $picked = $this->pick($files, $ids);
        $texts = $this->texts($picked);
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
            'checked' => $only ? [$only->scanId()] : ($ids ?: $this->checked($files)),
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
        $picked = $this->pick($subject->files(), $this->ids($request->input('ids', [])))->take(ScanAttachments::MAX_FILES);
        $ids = $picked->map->scanId()->values()->all();
        if (! $ids) {
            return redirect($subject->url());
        }
        $todo = $picked->filter(fn (ScanFile $f) => DocumentText::cached($f) === null)->map->scanId()->values()->all();
        ScanAttachments::enqueue($subject, $todo);

        return redirect($subject->url().'?'.http_build_query(['ids' => $ids]));
    }

    /** Положить выбранное; сколько полей ушло — для итога (машина — одно поле). */
    private function put(Request $request, Subject $subject): int
    {
        $picked = $this->pick($subject->files(), $this->ids($request->input('ids', [])));
        $rows = $this->rows($subject, $picked, $this->texts($picked));
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

    /** Отмечены сразу: документы и фото таблички VIN, СТС, ПТС (по имени), не больше лимита пачки. @return list<string> */
    private function checked(Collection $files): array
    {
        $docs = $files->reject->isPhoto()->map->scanId();
        $photos = $files->filter(fn (ScanFile $f) => $f->isPhoto() && preg_match(self::PHOTO_WITH_TEXT, $f->scanName()))->take(self::PHOTOS_CHECKED)->map->scanId();

        return $docs->concat($photos)->take(ScanAttachments::MAX_FILES)->values()->all();
    }

    /** Номера файлов из адреса или формы: `812`, `m45`; прочее отбрасывается. @return list<string> */
    private function ids(mixed $ids): array
    {
        return array_values(array_filter(array_map('strval', (array) $ids), fn (string $id) => preg_match('/^m?\d+$/', $id) === 1));
    }

    /** Файлы предмета с этими номерами, в порядке предмета. @param Collection<int, ScanFile> $files @param list<string> $ids @return Collection<int, ScanFile> */
    private function pick(Collection $files, array $ids): Collection
    {
        return $ids ? $files->filter(fn (ScanFile $f) => in_array($f->scanId(), $ids, true))->values() : collect();
    }

    /** Прочитанное по файлам; null — ещё не читали. @return Collection<string, ?string> */
    private function texts(Collection $picked): Collection
    {
        return $picked->mapWithKeys(fn (ScanFile $f) => [$f->scanId() => DocumentText::cached($f)]);
    }

    /** @param Collection<int, ScanFile> $picked @param Collection<string, ?string> $texts */
    private function rows(Subject $subject, Collection $picked, Collection $texts): array
    {
        return ScanFields::of($subject->current(), $picked->map(fn (ScanFile $f) => [$f, (string) $texts[$f->scanId()], $f->isPhoto()]), $subject->fields());
    }
}
