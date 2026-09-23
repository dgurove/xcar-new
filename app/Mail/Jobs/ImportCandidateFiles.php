<?php

namespace App\Mail\Jobs;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\PinThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Message;
use App\Mail\Scope;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Письмо цепочки «Из писем»: вложения закрепляются в blobs, иначе живут только в ящике и лента миниатюр в
 * окне писем каждый раз тянула бы их оттуда. Своих фото у цепочки нет: к ТС их приносит импорт ветки.
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

    public function handle(PinThread $pin, Publisher $publish): void
    {
        $candidate = Candidate::find($this->candidateId);
        $message = Message::find($this->messageId);
        if (! $candidate || ! $message || $candidate->state === CandidateState::Promoted) {
            return;
        }
        $pin->message($message);
        // Файлы доехали — экран «Из писем» перечитывается: в ленте писем миниатюры появляются только теперь.
        $publish->refresh($candidate->scope === Scope::Park ? Topics::PARK : Topics::STAFF, [$candidate->scope === Scope::Park ? '/requests/from-mail' : '/offers/from-mail']);
    }
}
