<?php

namespace App\Offers;

use App\Mail\Account;
use App\Mail\Message;
use App\Mail\Template;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запланированное письмо поставщику (27.11.2026, `Offers\PlannedLetters`): форма окна письма (`data`), ждёт «Беру в работу».
 * `state`: `pending` — ждёт, `sent` — письмо заведено и передано почте (`message_id`), `cancelled` — не нужно (`cancel_reason`),
 * `failed` — не смогли завести (`error`).
 */
#[Fillable(['offer_id', 'deal_id', 'template_id', 'account_id', 'created_by', 'data', 'state', 'message_id', 'error', 'cancel_reason', 'released_at', 'cancelled_at'])]
class PlannedLetter extends Model
{
    use FastAttributes;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['data' => 'array', 'released_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    /** Кому — адреса строкой, как в форме. */
    public function to(): string
    {
        return (string) ($this->data['to'] ?? '');
    }

    public function subject(): string
    {
        return trim((string) ($this->data['subject'] ?? '')) ?: '(без темы)';
    }

    /** Текст письма без разметки — для просмотра в шторке. */
    public function text(): string
    {
        return trim(app(\App\Mail\Parser::class)->htmlToText((string) ($this->data['body'] ?? '')));
    }

    /** Вложения: путь в outbox → имя файла. @return array<string, string> */
    public function files(): array
    {
        return collect((array) ($this->data['files'] ?? []))->filter(fn ($p) => str_starts_with((string) $p, 'outbox/'))
            ->mapWithKeys(fn ($p) => [$p => substr(basename((string) $p), 37)])->all();
    }
}
