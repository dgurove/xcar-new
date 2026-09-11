<?php

namespace App\Mail;

use App\Offers\Offer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Машина, вычитанная из письма страховой: поля с источником, письмо и ветка, откуда взялась. */
#[Fillable(['code', 'message_id', 'thread_id', 'subject', 'state', 'extracted', 'proposed', 'offer_id'])]
class Candidate extends Model
{
    protected $table = 'mail_candidates';

    protected function casts(): array
    {
        return ['state' => CandidateState::class, 'extracted' => 'array', 'proposed' => 'array'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function value(string $field): mixed
    {
        return $this->extracted[$field]['value'] ?? null;
    }

    public function title(): string
    {
        return trim(($this->value('brand') ?? '').' '.($this->value('model') ?? '')) ?: ($this->subject ?: 'Письмо');
    }
}
