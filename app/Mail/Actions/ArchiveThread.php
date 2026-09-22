<?php

namespace App\Mail\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Thread;
use App\Support\Nav;

/**
 * Архив как в Gmail: ветка уходит из «Входящих», находится поиском и в «Архиве», новое чужое письмо
 * возвращает её (Ingest). Один архив на всё: кандидат «Из писем» в архиве — его ветки тоже.
 */
final class ArchiveThread
{
    public function __invoke(Thread $thread, bool $archive = true): void
    {
        $thread->forceFill(['archived_at' => $archive ? now() : null])->saveQuietly();
        Nav::forgetStaffCounts();
    }

    public function candidate(Candidate $candidate, bool $archive = true): void
    {
        foreach ($candidate->threads() as $thread) {
            $this($thread, $archive);
        }
        Thread::where('candidate_id', $candidate->id)->update(['archived_at' => $archive ? now() : null]);
    }

    /** Ветку из архива достали руками — если это ветка архивного кандидата, он тоже снова ждёт. */
    public function restoreWithCandidate(Thread $thread): void
    {
        $this($thread, false);
        if ($thread->candidate_id && ($candidate = Candidate::find($thread->candidate_id)) && $candidate->state === CandidateState::Rejected) {
            $candidate->update(['state' => CandidateState::New]);
            app(ChainBuilder::class)->fold($candidate); // письма архива заморожены — свёртка прочитает их заново
        }
    }
}
