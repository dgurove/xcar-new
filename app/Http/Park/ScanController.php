<?php

namespace App\Http\Park;

use App\Mail\Actions\ApplyScan;
use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Mail\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «✨ Распознать» у цепочки «Из писем»: окно (`x-mail.scan-window`) в три шага одного фрейма.
 * - Файлы: документы и фото цепочки плитками, документы отмечены.
 * - Чтение: отмеченные списком, у каждого — крутится или готов; ставит `Jobs\ScanAttachments`, окно перечитывается
 *   по событию `scan`.
 * - Поля: что нашлось (`ScanFields`), где вариантов несколько — выбор; «Подставить» и «Завести» — `ApplyScan`.
 */
final class ScanController
{
    public function show(Request $request, Candidate $candidate)
    {
        $this->guard($candidate);
        $files = $this->files($candidate);
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
            'candidate' => $candidate,
            'step' => $step,
            'files' => $files,
            'picked' => $picked,
            'checked' => $ids ?: $files->reject->isPhoto()->pluck('id')->all(),
            'texts' => $texts,
            'reading' => $reading,
            'rows' => $step === 'fields' ? $this->rows($candidate, $picked, $texts) : null,
            'ids' => $ids,
            'max' => ScanAttachments::MAX_FILES,
        ]);
    }

    public function scan(Request $request, Candidate $candidate)
    {
        $this->guard($candidate);
        $ids = $this->files($candidate)->whereIn('id', array_map('intval', (array) $request->input('ids', [])))
            ->take(ScanAttachments::MAX_FILES)->pluck('id')->sort()->values()->all();
        if (! $ids) {
            return redirect("/requests/from-mail/{$candidate->id}/scan");
        }
        $todo = Attachment::whereIn('id', $ids)->get()->filter(fn (Attachment $a) => DocumentText::cached($a) === null)->pluck('id')->values()->all();
        if ($todo) {
            ScanAttachments::mark($todo);
            ScanAttachments::dispatch($candidate->id, $todo);
        }

        return redirect("/requests/from-mail/{$candidate->id}/scan?".http_build_query(['ids' => $ids]));
    }

    public function apply(Request $request, Candidate $candidate, ApplyScan $apply)
    {
        $this->guard($candidate);
        $picked = $this->files($candidate)->whereIn('id', array_map('intval', (array) $request->input('ids', [])))->values();
        $rows = $this->rows($candidate, $picked, $picked->mapWithKeys(fn (Attachment $a) => [$a->id => DocumentText::cached($a)]));
        $apply($candidate, ScanFields::chosen($rows, (array) $request->input('pick', [])));

        return $request->input('then') === 'create'
            ? redirect('/requests/new?candidate='.$candidate->id)
            : redirect()->to(url()->previous('/requests/from-mail'));
    }

    private function guard(Candidate $candidate): void
    {
        abort_unless($candidate->scope === Scope::Park && $candidate->state === CandidateState::New, 404);
    }

    /** @param Collection<int, Attachment> $picked @param Collection<int, ?string> $texts */
    private function rows(Candidate $candidate, Collection $picked, Collection $texts): array
    {
        return ScanFields::of($candidate, $picked->map(fn (Attachment $a) => [$a, (string) $texts[$a->id], $a->isPhoto()]));
    }

    /**
     * Файлы цепочки, которые «✨» умеет прочитать, по порядку писем и без повторов: один скан в «ч.1» и в пересылке
     * — одна плитка. Документы первыми: за заявкой не листать три десятка фото. @return Collection<int, Attachment>
     */
    private function files(Candidate $candidate): Collection
    {
        $candidate->loadMissing('messages.attachments');

        return $candidate->messages->flatMap(fn ($m) => $m->files())
            ->filter(fn (Attachment $a) => DocumentText::scannable($a))
            ->unique(fn (Attachment $a) => $a->blob_sha ?: $a->fileKey())
            ->sortBy(fn (Attachment $a) => $a->isPhoto() ? 1 : 0)
            ->values();
    }
}
