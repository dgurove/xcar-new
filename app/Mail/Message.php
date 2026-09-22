<?php

namespace App\Mail;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'account_id', 'folder_id', 'thread_id', 'direction', 'imap_uid', 'uid_validity', 'message_id', 'in_reply_to',
    'references_header', 'subject', 'subject_normalized', 'from_email', 'from_name', 'to_preview', 'date_at',
    'internal_at', 'size', 'is_seen', 'is_answered', 'is_flagged', 'is_draft', 'is_deleted', 'text_body',
    'html_body', 'preview', 'headers', 'parse_state', 'parse_error', 'send_state', 'send_error', 'send_attempts',
    'sent_at', 'appended_to_sent_at', 'has_attachments', 'attachments_count', 'created_by', 'intent', 'parsed', 'parser_version',
])]
class Message extends Model
{
    protected $table = 'mail_messages';

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'parse_state' => ParseState::class,
            'send_state' => SendState::class,
            'headers' => 'array',
            'parsed' => 'array',
            'date_at' => 'datetime',
            'internal_at' => 'datetime',
            'sent_at' => 'datetime',
            'appended_to_sent_at' => 'datetime',
            'frozen_at' => 'datetime',
            'is_seen' => 'bool', 'is_answered' => 'bool', 'is_flagged' => 'bool', 'is_draft' => 'bool', 'is_deleted' => 'bool',
            'has_attachments' => 'bool',
            'imap_uid' => 'int', 'uid_validity' => 'int',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'message_id')->orderBy('position');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id')->orderBy('position');
    }

    public function isOutgoing(): bool
    {
        return $this->direction === Direction::Out;
    }

    /** Поля из разбора письма (`ReadLetter`): марка, номер, VIN, страхователь… */
    public function fields(): array
    {
        return $this->parsed['fields'] ?? [];
    }

    public function field(string $name): mixed
    {
        return $this->parsed['fields'][$name]['value'] ?? null;
    }

    /** Номера-тождества письма: code:/vin:/plate:. @return list<string> */
    public function keys(): array
    {
        return $this->parsed['keys'] ?? [];
    }

    /** Свои слова письма без цитат и подписи. */
    public function ownText(): string
    {
        return $this->parsed['own_text'] ?? Extraction\Intent::excerpt($this->text_body ?: strip_tags((string) $this->html_body), 2000);
    }

    public function isRead(): bool
    {
        return (int) $this->parser_version === Reading\ReadLetter::VERSION;
    }

    /** Наше письмо: отправлено с ящика или своим человеком с личного адреса (Корабельников с mail.ru отвечает вендору как мы). */
    public function isOurs(): bool
    {
        return $this->direction === Direction::Out || in_array(mb_strtolower((string) $this->from_email), self::ownEmails(), true);
    }

    /**
     * Файлы этого письма на диск не ложатся: оно старше даты «Файлы из писем с» у ящика или заморожено
     * (`frozen_at`: цепочка закрыта или ТС выдана — распарсенное и файлы сняты, письмо не читается и не пересобирается).
     */
    public function filesFrozen(): bool
    {
        if ($this->frozen_at !== null) {
            return true;
        }
        $from = $this->account?->files_from;

        return $from !== null && $this->date_at !== null && $this->date_at->lt($from);
    }

    /** @return list<string> */
    public static function ownEmails(): array
    {
        return Cache::remember('mail:own-emails', 60, fn () => User::query()->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->filter()->values()->all());
    }

    public function existsOnServer(): bool
    {
        return $this->imap_uid !== null && $this->folder_id !== null;
    }

    /** @return Collection<int, Address> */
    public function addressesOfKind(AddressKind $kind): Collection
    {
        return $this->addresses->where('kind', $kind->value)->sortBy('position')->values();
    }

    /** Куда отвечать: Reply-To, если есть, иначе отправитель. */
    public function replyToAddress(): ?string
    {
        return $this->addressesOfKind(AddressKind::ReplyTo)->first()?->email ?: $this->from_email;
    }

    /** Цепочка References для ответа: цепочка родителя плюс сам родитель. */
    public function referencesForReply(): string
    {
        $chain = preg_split('/\s+/', (string) $this->references_header, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($this->message_id) {
            $chain[] = $this->message_id;
        }

        return implode(' ', array_unique($chain));
    }

    /** Файлы, которые видны как вложения: без картинок, вставленных в тело. */
    public function files(): Collection
    {
        return $this->attachments->reject(fn (Attachment $a) => $a->is_inline)->values();
    }
}
