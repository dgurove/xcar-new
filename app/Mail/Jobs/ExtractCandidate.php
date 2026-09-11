<?php

namespace App\Mail\Jobs;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Extractor;
use App\Mail\Message;
use App\Offers\Offer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Из входящего письма — кандидат в офферы. Письмо по уже заведённому убытку кандидатом не становится. */
final class ExtractCandidate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(Extractor $extractor): void
    {
        $message = Message::with('thread')->find($this->messageId);
        if (! $message || $message->direction !== Direction::In) {
            return;
        }
        $fields = $extractor->extract($message->subject, $message->text_body ?: $message->html_body, $message->from_email);
        $code = Code::normalize($fields['code']['value'] ?? null);
        if (! Extractor::looksLikeOffer($fields)) {
            return;
        }
        if ($code && Offer::where('claim_ref_key', self::key($code))->exists()) {
            return;
        }

        $existing = Candidate::whereIn('state', [CandidateState::New, CandidateState::Rejected])
            ->where(fn ($q) => $code ? $q->where('code', $code) : $q->where('message_id', $message->id))->first();
        if (! $existing) {
            Candidate::create(['code' => $code, 'message_id' => $message->id, 'thread_id' => $message->thread_id, 'subject' => $message->subject, 'extracted' => $fields]);

            return;
        }
        // Многочастное письмо: новые поля дописываются к прежним, а свежие значения ложатся рядом.
        $existing->update(['extracted' => $fields + ($existing->extracted ?? []), 'proposed' => $fields, 'thread_id' => $existing->thread_id ?? $message->thread_id]);
    }

    public static function key(string $code): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $code));
    }
}
