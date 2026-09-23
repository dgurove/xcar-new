<?php

namespace App\Mail;

use App\Cars\Brand;
use App\Cars\CarModel;
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

/**
 * Цепочка писем об одной ТС («Из писем»): письма, связанные номером убытка, VIN, госномером или веткой.
 * Всё вычисляемое (`code`, `key`, `vendor_id`, `extracted`, `stages`) — свёртка `Chains\ChainBuilder::fold` по
 * `messages()`; рукотворное — `state`, `vehicle_id`/`offer_id`. `message_id`/`thread_id` — первое письмо вендора.
 * Своих фото у цепочки нет: вложения писем закреплены в blobs и видны лентой миниатюр в окне писем, а при
 * «Завести» их к ТС или предложению приносит импорт ветки (`LinkThread` → `ImportThreadFiles`).
 */
#[Fillable(['scope', 'code', 'key', 'vendor_id', 'message_id', 'thread_id', 'subject', 'state', 'extracted', 'proposed', 'offer_id', 'vehicle_id', 'messages_count', 'last_message_at', 'closed_at'])]
class Candidate extends Model
{
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

    /** Что просят заявкой: вывезти или привезут сами. Одно слово на список «Из писем» и на разбор письма. */
    public function requestTag(): string
    {
        return $this->value('request') === 'tow' ? 'Эвакуация' : 'Приём';
    }

    /** Марка и модель из письма справочником: `[?Brand, ?CarModel]` — одно место на форму и на автозаведение. */
    public function cars(): array
    {
        $brand = $this->value('brand') ? Brand::resolve($this->value('brand')) : null;

        return [$brand, $brand && $this->value('model') ? CarModel::resolve($brand, $this->value('model')) : null];
    }

    /** Поля ТС из письма: форма разбора показывает их сотруднику, `StoreByLetters` заводит по ним сам. */
    public function vehicleData(): array
    {
        [$brand, $model] = $this->cars();

        return [
            'ref' => $this->code, 'vin' => $this->value('vin'), 'plate' => $this->value('plate'), 'year' => $this->value('year'),
            'brand_id' => $brand?->id, 'model_id' => $model?->id, 'category' => $this->value('category'), 'color' => $this->value('color'),
            'vendor_id' => $this->value('vendor_id') ?? Vendor::forSender($this->value('sender'))?->id,
            'contact_name' => $this->value('insured_name'),
            'contact_phone' => $this->value('insured_phone') ?? ((array) $this->value('phones'))[0] ?? null,
            'value' => $this->value('value'),
            'flags' => $this->value('flags') ?: [], 'docs_required' => $this->value('docs_required') ?: [],
        ];
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
