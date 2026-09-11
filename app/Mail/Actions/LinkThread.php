<?php

namespace App\Mail\Actions;

use App\Mail\Extraction\CodeMatcher;
use App\Mail\Jobs\ExtractCandidate;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Offer;

/** Ветка ↔ оффер: по коду убытка в теме или теле, либо руками. */
final class LinkThread
{
    public function __construct(private CodeMatcher $matcher) {}

    public function __invoke(Thread $thread, Offer $offer): void
    {
        $thread->update(['offer_id' => $offer->id]);
    }

    public function auto(Message $message): ?Offer
    {
        $thread = $message->thread;
        if (! $thread || $thread->offer_id) {
            return null;
        }
        $codes = array_unique([...$this->matcher->findAll($message->subject), ...$this->matcher->findAll($message->text_body ?: $message->html_body)]);
        foreach ($codes as $code) {
            if ($offer = Offer::where('claim_ref_key', ExtractCandidate::key($code))->first()) {
                $thread->update(['offer_id' => $offer->id]);

                return $offer;
            }
        }
        if ($vin = $this->vin($message)) {
            if ($offer = Offer::where('vin', $vin)->latest()->first()) {
                $thread->update(['offer_id' => $offer->id]);

                return $offer;
            }
        }

        return null;
    }

    private function vin(Message $message): ?string
    {
        $text = mb_strtoupper($message->subject.' '.($message->text_body ?: ''));

        return preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/', $text, $m) && strlen(count_chars($m[0], 3)) > 1 ? $m[0] : null;
    }
}
