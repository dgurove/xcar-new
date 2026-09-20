<?php

namespace App\Mail;

use App\Mail\Extraction\Code;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Машина, вычитанная из писем страховой: одна ТС — один кандидат, сколько бы писем о ней ни пришло.
 * Тождество — `key`: номер убытка (в любом написании), без него VIN, без VIN госномер, без всего — ветка.
 * `message_id`/`thread_id` — первое письмо и его ветка, все письма — `messages()`.
 * Из фото писем у кандидата один кадр карточки `card` (`CandidateCard`, диск `hot`); сами вложения закреплены в blobs,
 * при «Завести» их к ТС или предложению приносит импорт ветки (`LinkThread` → `ImportThreadFiles`).
 */
#[Fillable(['scope', 'code', 'key', 'vendor_id', 'message_id', 'thread_id', 'subject', 'state', 'extracted', 'proposed', 'offer_id', 'vehicle_id', 'messages_count', 'last_message_at'])]
class Candidate extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'mail_candidates';

    protected function casts(): array
    {
        return ['scope' => Scope::class, 'state' => CandidateState::class, 'extracted' => 'array', 'proposed' => 'array', 'last_message_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'mail_candidate_messages', 'candidate_id', 'message_id')->orderBy('mail_messages.date_at');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /** Ветки всех писем кандидата, без повторов. @return Collection<int, Thread> */
    public function threads(): Collection
    {
        return $this->messages->loadMissing('thread')->pluck('thread')->filter()->unique('id')->values();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('card')->useDisk('hot')->singleFile();
    }

    public function card(): ?Media
    {
        return $this->getFirstMedia('card');
    }

    /** Сколько фото во вложениях всех писем кандидата (по описи, без inline-картинок тела). */
    public function photosCount(): int
    {
        return $this->messages->loadMissing('attachments')->flatMap->attachments->filter(fn ($a) => ! $a->is_inline && $a->isImage())->count();
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function value(string $field): mixed
    {
        return $this->extracted[$field]['value'] ?? null;
    }

    public function title(): string
    {
        return trim(($this->value('brand') ?? '').' '.($this->value('model') ?? '')) ?: ($this->subject ?: 'Письмо');
    }

    /** Пришло письмо с полями, которых не было или которые отличаются — карточку стоит перепроверить. */
    public function hasNews(): bool
    {
        return $this->proposed && $this->proposed !== $this->extracted;
    }

    /** Госномер и VIN в письме пишут как попало — ключ одинаков для «а123вс716» и «А 123 ВС 716». */
    public static function plateKey(?string $plate): ?string
    {
        $plate = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $plate));

        return $plate !== '' ? $plate : null;
    }

    /** Самое сильное из тождеств: убыток → VIN → госномер → ветка → письмо. */
    public static function strongest(array $keys): ?string
    {
        $rank = fn (string $k) => array_search(strtok($k, ':'), ['code', 'vin', 'plate', 'thread', 'message'], true);
        $keys = array_values(array_filter($keys));
        usort($keys, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $keys[0] ?? null;
    }

    /** Все тождества кандидата: из его полей и веток всех писем — письмо с одним VIN, но без номера убытка ляжет сюда же. @return list<string> */
    public function allIdentities(): array
    {
        $ids = self::identities($this->extracted ?? [], null);
        foreach ($this->messages->pluck('thread_id')->filter()->unique() as $threadId) {
            $ids[] = 'thread:'.$threadId;
        }

        return array_values(array_unique([$this->key, ...$ids]));
    }

    /**
     * Тождества письма по силе: убыток, VIN, госномер, ветка. По ним ищется открытый кандидат,
     * первое — его новый ключ, если прежний был слабее.
     *
     * @return list<string>
     */
    public static function identities(array $fields, ?int $threadId): array
    {
        $value = fn (string $f) => $fields[$f]['value'] ?? null;

        return array_values(array_filter([
            $value('code') ? 'code:'.Code::key($value('code')) : null,
            $value('vin') ? 'vin:'.strtoupper((string) $value('vin')) : null,
            self::plateKey($value('plate')) ? 'plate:'.self::plateKey($value('plate')) : null,
            $threadId ? 'thread:'.$threadId : null,
        ]));
    }
}
