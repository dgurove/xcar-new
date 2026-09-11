<?php

namespace App\Mail;

use App\Offers\Offer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'root_message_id', 'subject', 'subject_normalized', 'participants', 'last_message_at', 'messages_count', 'unread_count', 'has_attachments', 'offer_id'])]
class Thread extends Model
{
    protected $table = 'mail_threads';

    protected function casts(): array
    {
        return ['participants' => 'array', 'last_message_at' => 'datetime', 'has_attachments' => 'bool'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('date_at');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** Собеседники без нас самих. */
    public function counterparts(): array
    {
        $own = mb_strtolower((string) $this->account?->email);

        return array_values(array_filter($this->participants ?? [], fn ($p) => ($p['email'] ?? '') !== $own));
    }
}
