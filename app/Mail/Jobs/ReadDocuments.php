<?php

namespace App\Mail\Jobs;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Extraction\DocumentText;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Threads;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Дочитать PDF письма парковки (`DocumentText::read`: слой или OCR скана) и перечитать письмо — теперь с машиной
 * из документа; незаведённую цепочку письма свернуть заново, ветку обновить. Ставит `ReadLetter::apply`, когда в
 * письме есть непрочитанные документы; после чтения они в кеше, второй раз задача не встаёт.
 */
final class ReadDocuments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(ReadLetter $reader, ChainBuilder $chains, Threads $threads): void
    {
        $message = Message::with(['account', 'attachments', 'thread'])->find($this->messageId);
        if (! $message) {
            return;
        }
        foreach (DocumentText::pending($message) as $attachment) {
            DocumentText::read($attachment);
        }
        $reader->apply($message);
        if ($message->thread) {
            $threads->refresh($message->thread);
        }
        $candidates = Candidate::whereHas('messages', fn ($q) => $q->where('mail_messages.id', $message->id))
            ->whereIn('state', [CandidateState::New, CandidateState::Rejected])->get();
        foreach ($candidates as $candidate) {
            $chains->fold($candidate);
        }
    }
}
