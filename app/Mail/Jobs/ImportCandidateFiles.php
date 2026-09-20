<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\PinThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\AttachmentImporter;
use App\Mail\Message;
use App\Mail\Scope;
use App\Offers\OfferState;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Кадры письма — в медиатеку кандидата «Из писем»: превью в карточке и лента «Из письма» на форме заявки,
 * пока ТС ещё не заведена. Вложения незаведённого кандидата живут только в ящике, поэтому сначала
 * закрепляем их в blobs (одно письмо), потом разбираем: только фото и архивы, документы кандидату не нужны.
 * Что уже лежит — по `sha` — не дублируется; при «Завести» кадры переезжают к ТС.
 */
final class ImportCandidateFiles implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 2;

    public function __construct(public int $candidateId, public int $messageId)
    {
        $this->onConnection('database-long')->onQueue('long')->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->candidateId.':'.$this->messageId;
    }

    public function handle(PinThread $pin, AttachmentImporter $importer, Publisher $publish): void
    {
        $candidate = Candidate::find($this->candidateId);
        $message = Message::find($this->messageId);
        if (! $candidate || ! $message || $candidate->state === CandidateState::Promoted) {
            return;
        }
        $pin->message($message);
        $added = $importer->import($candidate, $importer->attachmentsOf($message->id, null), 'photos', '', ['stage' => 'mail']);
        // Пока тянули из ящика, кандидата завели — кадры едут туда же, куда уехали первые.
        $candidate->refresh();
        if ($candidate->state === CandidateState::Promoted && ($target = $candidate->vehicle ?? $candidate->offer)) {
            $candidate->moveMediaTo($target, $candidate->offer && $candidate->offer->state !== OfferState::Draft ? ['hidden' => true] : []);

            return;
        }
        if ($added['photos']) {
            $publish->refresh($candidate->scope === Scope::Park ? Topics::PARK : Topics::STAFF, [$candidate->scope === Scope::Park ? '/requests/from-mail' : '/offers/from-mail']);
        }
    }
}
