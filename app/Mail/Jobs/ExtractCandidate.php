<?php

namespace App\Mail\Jobs;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Extractor;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Message;
use App\Mail\Scope;
use App\Offers\Offer;
use App\Park\Events\CandidateArrived;
use App\Park\Vehicle;
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
        $message = Message::with(['thread', 'account'])->find($this->messageId);
        if (! $message || $message->direction !== Direction::In) {
            return;
        }
        $park = $message->account->scope === Scope::Park;
        $body = $message->text_body ?: $message->html_body;
        $fields = $park ? (new ParkExtractor)->extract($message->subject, $body, $message->from_email, $message->date_at)
            : $extractor->extract($message->subject, $body, $message->from_email, $message->date_at);
        $code = Code::normalize($fields['code']['value'] ?? null);
        if ($park ? ! ParkExtractor::looksLikeRequest($fields) : ! Extractor::looksLikeOffer($fields)) {
            return;
        }
        if ($code && ($park ? Vehicle::where('ref_key', self::key($code))->exists() : Offer::where('claim_ref_key', self::key($code))->exists())) {
            return;
        }

        $scope = $park ? Scope::Park : Scope::Offers;
        $existing = Candidate::where('scope', $scope)->whereIn('state', [CandidateState::New, CandidateState::Rejected])
            ->where(fn ($q) => $code ? $q->where('code', $code) : $q->where('message_id', $message->id))->first();
        if (! $existing) {
            $candidate = Candidate::create(['scope' => $scope, 'code' => $code, 'message_id' => $message->id, 'thread_id' => $message->thread_id, 'subject' => $message->subject, 'extracted' => $fields]);
            if ($park) {
                CandidateArrived::dispatch($candidate);
            }

            return;
        }
        // Многочастное письмо: новые поля дописываются к прежним, а свежие значения ложатся рядом.
        $existing->update(['extracted' => $fields + ($existing->extracted ?? []), 'proposed' => $fields, 'thread_id' => $existing->thread_id ?? $message->thread_id]);
    }

    public static function key(string $code): string
    {
        return (string) Code::key($code);
    }
}
