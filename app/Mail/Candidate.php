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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Цепочка писем об одной ТС («Из писем»): письма, связанные номером убытка, VIN, госномером или веткой.
 * Всё вычисляемое (`code`, `key`, `vendor_id`, `extracted`, `stages`) — свёртка `Chains\ChainBuilder::fold` по
 * `messages()`; рукотворное — `state`, `vehicle_id`/`offer_id`. `message_id`/`thread_id` — первое письмо вендора.
 * Из фото писем у кандидата один кадр карточки `card` (`CandidateCard`, диск `hot`); сами вложения закреплены в blobs,
 * при «Завести» их к ТС или предложению приносит импорт ветки (`LinkThread` → `ImportThreadFiles`).
 */
#[Fillable(['scope', 'code', 'key', 'vendor_id', 'message_id', 'thread_id', 'subject', 'state', 'extracted', 'proposed', 'offer_id', 'vehicle_id', 'messages_count', 'last_message_at', 'closed_at'])]
class Candidate extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'mail_candidates';

    protected function casts(): array
    {
        return ['scope' => Scope::class, 'state' => CandidateState::class, 'stage' => CandidateStage::class, 'stages' => 'array', 'extracted' => 'array', 'proposed' => 'array', 'last_message_at' => 'datetime', 'closed_at' => 'datetime'];
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

    /** Последнее письмо цепочки: его заголовок (`Chains\NodeTitle`) идёт в карточку и строку «Из писем». */
    public function lastLetter(): ?Message
    {
        return $this->messages->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->last();
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

    /** Марка и модель; без них заголовок так и говорит — номер убытка и госномер идут чипами рядом. */
    public function title(): string
    {
        return trim(($this->value('brand') ?? '').' '.($this->value('model') ?? '')) ?: 'Марка не распознана';
    }

    /** Этап цепочки по письмам: заявка, принята, продана, выдана. @return ?array{stage: string, at: ?string, message_id: int, title: string} */
    public function stageOf(CandidateStage $stage): ?array
    {
        foreach ($this->stages ?? [] as $s) {
            if (($s['stage'] ?? null) === $stage->value) {
                return $s;
            }
        }

        return null;
    }

    /** Слово этапа для чипа: «ждёт приёма», «на парковке с 15 сен», «продана, заберёт покупатель», «выдана 12 мая». */
    public function stageLabel(): string
    {
        $stage = $this->stage ?? CandidateStage::Intake;
        if ($stage === CandidateStage::Stored && ($at = $this->stageOf($stage)['at'] ?? null)) {
            return 'на парковке с '.Carbon::parse($at)->translatedFormat('j M');
        }
        if ($stage === CandidateStage::Released && ($at = $this->stageOf($stage)['at'] ?? null)) {
            return 'выдана '.Carbon::parse($at)->translatedFormat('j M');
        }

        return $stage->label();
    }

    /**
     * Что от нас ждут по цепочке, одной строкой для списка «Из писем»: заявка — позвонить страхователю или ждать
     * привоза; принята по письмам — завести стоящей; продана — завести и выдать; выдана — закрыть.
     * `phone: false` — там, где номер и так стоит рядом кнопкой звонка (разбор письма).
     */
    public function todo(bool $phone = true): string
    {
        $stage = $this->stage ?? CandidateStage::Intake;
        $who = trim(($this->value('insured_name') ?? '').($phone ? ' '.($this->value('insured_phone') ?? ((array) $this->value('phones'))[0] ?? '') : ''));
        $when = fn (?string $at) => $at ? Carbon::parse($at)->translatedFormat('j M') : null;
        if ($stage === CandidateStage::Released) {
            return 'Выдана '.$when($this->stageOf($stage)['at'] ?? null).', в системе не заводилась';
        }
        if ($stage === CandidateStage::Sold) {
            $sold = $this->stageOf($stage) ?? [];
            $buyer = trim(($sold['name'] ?? '').' '.($sold['phone'] ?? ''));

            return 'Продана '.$when($sold['at'] ?? null).($buyer ? ', заберёт '.$buyer : '').': завести и выдать';
        }
        if ($stage === CandidateStage::Stored) {
            return 'Принята '.$when($this->stageOf($stage)['at'] ?? null).' по письмам, в системе ещё нет: завести стоящей';
        }
        $planned = $this->value('planned_at') ? Carbon::parse($this->value('planned_at'))->translatedFormat('j M, H:i') : null;

        return match (true) {
            $planned !== null => 'Привезут '.$planned.($who ? ', '.$who : ''),
            $who !== '' => 'Позвонить страхователю '.$who.', договориться о приёме',
            $this->value('request') === 'tow' => 'Нужен эвакуатор'.($this->value('location') ? ' из '.$this->value('location') : ''),
            default => 'Заявка на приём, договориться о дате',
        };
    }

    /** Есть ли у кандидата имя машины — иначе заголовок несёт номер. */
    public function hasCar(): bool
    {
        return (bool) $this->value('brand');
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
