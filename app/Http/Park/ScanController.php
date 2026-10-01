<?php

namespace App\Http\Park;

use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Extraction\AttachmentClassifier;
use App\Mail\Extraction\DocumentText;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ScanAttachments;
use App\Mail\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «✨ Распознать» у цепочки «Из писем»: окно (`x-mail.scan-window`) с плитками всех файлов цепочки — документы
 * отмечены, фото нет; «Распознать» ставит `Jobs\ScanAttachments`, окно ждёт события `scan` (или переспрашивает,
 * если хаба нет); всё прочитано — шаг «что подставить» (`ScanFields`): выбор уходит в `Candidate::chosen`, и
 * дальше либо «Завести» (разбор письма с подставленным), либо просто карточка с маркой.
 */
final class ScanController
{
    public function show(Request $request, Candidate $candidate)
    {
        $this->guard($candidate);
        $files = $this->files($candidate);
        $ids = array_map('intval', (array) $request->query('ids', []));
        $picked = $ids ? $files->whereIn('id', $ids) : collect();
        $busy = $picked->isNotEmpty() && ScanAttachments::busy($candidate->id);
        // Шаг полей — когда отмеченное прочитано и задача досвернула цепочку; «Файлы» из шага полей — назад к плиткам.
        $done = $picked->isNotEmpty() && ! $busy && ! $request->boolean('files') && $picked->every(fn (Attachment $a) => DocumentText::cached($a) !== null);

        return view('park.requests.scan', [
            'candidate' => $candidate,
            'files' => $files,
            'picked' => $ids ?: $files->reject(fn (Attachment $a) => $this->isPhoto($a))->pluck('id')->all(),
            'waiting' => $busy,
            'busy' => $busy ? $picked->filter(fn (Attachment $a) => DocumentText::cached($a) === null)->pluck('id')->all() : [],
            'rows' => $done ? ScanFields::of($candidate, $picked->map(fn (Attachment $a) => [$a, (string) DocumentText::cached($a), $this->isPhoto($a)])) : null,
            'ids' => $ids,
            'isPhoto' => fn (Attachment $a) => $this->isPhoto($a),
        ]);
    }

    public function scan(Request $request, Candidate $candidate)
    {
        $this->guard($candidate);
        $ids = $this->files($candidate)->whereIn('id', array_map('intval', (array) $request->input('ids', [])))->pluck('id')->sort()->values()->all();
        if (! $ids) {
            return redirect("/requests/from-mail/{$candidate->id}/scan");
        }
        $todo = Attachment::whereIn('id', $ids)->get()->filter(fn (Attachment $a) => DocumentText::cached($a) === null);
        if ($todo->isNotEmpty()) {
            ScanAttachments::busy($candidate->id, true);
            ScanAttachments::dispatch($candidate->id, $todo->pluck('id')->values()->all(), (int) $request->user()->id);
        }

        return redirect("/requests/from-mail/{$candidate->id}/scan?".http_build_query(['ids' => $ids]));
    }

    public function apply(Request $request, Candidate $candidate, ChainBuilder $chains)
    {
        $this->guard($candidate);
        $ids = array_map('intval', (array) $request->input('ids', []));
        $picked = $this->files($candidate)->whereIn('id', $ids);
        $rows = ScanFields::of($candidate, $picked->map(fn (Attachment $a) => [$a, (string) DocumentText::cached($a), $this->isPhoto($a)]));
        $chosen = ScanFields::chosen($rows, (array) $request->input('pick', []));
        if ($chosen) {
            $candidate->forceFill(['chosen' => array_merge($candidate->chosen ?? [], $chosen)])->saveQuietly();
            $chains->fold($candidate);
        }

        return $request->input('then') === 'create'
            ? redirect('/requests/new?candidate='.$candidate->id)
            : redirect('/requests/from-mail');
    }

    private function guard(Candidate $candidate): void
    {
        abort_unless($candidate->scope === Scope::Park && $candidate->state === CandidateState::New, 404);
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
            ->unique(fn (Attachment $a) => $a->blob_sha ?: mb_strtolower((string) $a->filename).'|'.$a->size)
            ->sortBy(fn (Attachment $a) => $this->isPhoto($a) ? 1 : 0)
            ->values();
    }

    /** Фото — картинка без вида документа в имени («СТС.jpg» — документ, как в шторке `Docs::fromLetters`). */
    private function isPhoto(Attachment $a): bool
    {
        return $a->isImage() && AttachmentClassifier::kindOf($a->filename) === null;
    }
}
