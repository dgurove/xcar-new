<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\PinThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\CandidateCard;
use App\Mail\Message;
use App\Mail\Scope;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Письмо кандидата «Из писем»: вложения закрепляются в blobs (иначе живут только в ящике), из первого фото
 * делается кадр карточки (`CandidateCard`) — заранее, чтобы список открывался сразу. Остальные фото
 * кандидату не нужны: при «Завести» их к ТС приносит импорт ветки.
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

    public function handle(PinThread $pin, CandidateCard $card, Publisher $publish): void
    {
        $candidate = Candidate::find($this->candidateId);
        $message = Message::find($this->messageId);
        if (! $candidate || ! $message || $candidate->state === CandidateState::Promoted) {
            return;
        }
        $pin->message($message);
        $had = $candidate->card() !== null;
        if ($card->make($candidate, $message) && ! $had) {
            $publish->refresh($candidate->scope === Scope::Park ? Topics::PARK : Topics::STAFF, [$candidate->scope === Scope::Park ? '/requests/from-mail' : '/offers/from-mail']);
        }
    }
}
