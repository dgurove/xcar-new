<?php

namespace App\Mail;

use App\Offers\Offer;
use App\Park\Vehicle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'root_message_id', 'subject', 'subject_normalized', 'participants', 'last_message_at', 'messages_count', 'unread_count', 'has_attachments', 'offer_id', 'vehicle_id'])]
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** Ветка ведётся по машине: файлы её писем закрепляются у нас, а не живут только в ящике. */
    public function isLinked(): bool
    {
        return $this->offer_id !== null || $this->vehicle_id !== null;
    }

    /** Собеседники без нас самих. */
    public function counterparts(): array
    {
        $own = mb_strtolower((string) $this->account?->email);

        return array_values(array_filter($this->participants ?? [], fn ($p) => ($p['email'] ?? '') !== $own));
    }
}
