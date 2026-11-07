<?php

namespace App\Http\Mail;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scan\CandidateSubject;
use App\Mail\Scan\Reader;
use App\Mail\Scope;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * Читалка «Завести» в разборе письма парковки (`x-mail.reader-body`, `Scan\Reader`): машины ещё нет, документы цепочки
 * читаются сами, поля формы заполняются по ходу. Ответы — JSON. Окна «✨ Распознать» больше нет (07.11.2026): документы
 * заведённой машины читаются сами, спорное — вариантами полей (`Cars\Sources`).
 */
final class ScanController
{
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

    public function readerReject(Request $request, Candidate $candidate)
    {
        $data = $request->validate(['field' => ['required', 'string', 'max:40'], 'text' => ['required', 'string', 'max:300']]);

        return response()->json(Reader::reject($this->chain($candidate), $data['field'], $data['text']));
    }

    private function chain(Candidate $candidate): CandidateSubject
    {
        $scope = Surface::current() === Surface::Park ? Scope::Park : Scope::Offers;
        abort_unless($candidate->scope === $scope && $candidate->state === CandidateState::New, 404);

        return new CandidateSubject($candidate);
    }

    /** @return list<string> */
    private function ids(mixed $ids): array
    {
        return array_values(array_filter(array_map('strval', (array) $ids), fn (string $id) => preg_match('/^m?\d+$/', $id) === 1));
    }
}
