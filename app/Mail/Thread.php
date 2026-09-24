<?php

namespace App\Mail;

use App\Offers\Offer;
use App\Park\Vehicle;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ветка писем. `keys` — её номера (code:/vin:/plate:, см. Extraction\Keys), `candidate_id` — кандидат «Из писем»,
 * `archived_at` — убрана из «Входящих» (как в Gmail: найдётся поиском, новое чужое письмо вернёт).
 */
#[Fillable(['account_id', 'root_message_id', 'subject', 'subject_normalized', 'participants', 'last_message_at', 'messages_count', 'unread_count', 'has_attachments', 'offer_id', 'vehicle_id', 'buyer', 'unlinked_at', 'keys', 'candidate_id', 'archived_at', 'vendor_id'])]
class Thread extends Model
{
    protected $table = 'mail_threads';

    protected function casts(): array
    {
        return ['participants' => 'array', 'last_message_at' => 'datetime', 'has_attachments' => 'bool', 'buyer' => 'bool', 'unlinked_at' => 'datetime', 'keys' => 'array', 'archived_at' => 'datetime',
            'needs_reply_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('date_at');
    }

    /** Последнее письмо ветки — строке списка нужны его первые слова (`preview`). */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->latestOfMany('date_at');
    }

    /** Последнее входящее — его смысл идёт тегом в строку почты и решает, ждёт ли ветка ответа. */
    public function latestIncoming(): HasOne
    {
        return $this->hasOne(Message::class, 'thread_id')->ofMany(['date_at' => 'max'], fn ($q) => $q->where('direction', Direction::In));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function attachments(): HasManyThrough
    {
        return $this->hasManyThrough(Attachment::class, Message::class, 'thread_id', 'message_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /** Ветки с любым из номеров (`keys` пересекаются с `$keys`). */
    public function scopeWithAnyKey($query, array $keys)
    {
        // `?|` спорит с плейсхолдерами PDO — та же проверка функцией.
        return $keys ? $query->whereRaw('jsonb_exists_any(keys, array['.implode(',', array_fill(0, count($keys), '?')).']::text[])', array_values($keys)) : $query->whereRaw('false');
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
