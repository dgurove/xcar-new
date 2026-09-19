<?php

namespace App\Park;

use App\Billing\Cadence;
use App\Billing\Charge;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\DamageZone;
use App\Mail\Extraction\Code;
use App\Mail\Template;
use App\Media\HasPhotos;
use App\Offers\Flag;
use App\Offers\Offer;
use App\Support\Phone;
use App\Users\User;
use App\Vendors\DocRequirement;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;

#[Fillable(['ref', 'vin', 'brand_id', 'model_id', 'year', 'plate', 'color', 'category', 'oversize', 'vendor_id', 'state', 'yard_id', 'accepted_at', 'released_at', 'damage_zones', 'damage_note', 'notes', 'offer_id',
    'contact_name', 'contact_phone', 'flags', 'docs_required', 'value',
    'cancelled_at', 'cancel_reason', 'spot', 'transit_started_at', 'mileage', 'fuel', 'idle_noticed_at',
    'owner_party_id', 'contract_kind', 'contract_no', 'contract_at', 'assigned_price', 'storage_rate', 'storage_rate_note', 'storage_billed_until', 'pts', 'sts',
    'sold_at', 'sold_message_id', 'pickup_name', 'pickup_phone', 'pickup_note', 'buyer_party_id', 'billing_cadence'])]
class Vehicle extends Model implements HasMedia
{
    use HasPhotos;

    protected $table = 'park_vehicles';

    protected function casts(): array
    {
        return ['state' => VehicleState::class, 'category' => Category::class, 'oversize' => 'bool', 'damage_zones' => 'array', 'flags' => 'array', 'docs_required' => 'array',
            'accepted_at' => 'datetime', 'released_at' => 'datetime', 'cancelled_at' => 'datetime', 'transit_started_at' => 'datetime', 'idle_noticed_at' => 'datetime', 'year' => 'int',
            'contract_at' => 'date', 'storage_billed_until' => 'date', 'storage_rate' => 'float', 'sold_at' => 'date', 'billing_cadence' => Cadence::class];
    }

    public function setRefAttribute(?string $value): void
    {
        $value = trim((string) $value) ?: null;
        $this->attributes['ref'] = $value;
        $this->attributes['ref_key'] = $value ? self::keyFor($value) : null;
    }

    public function setVinAttribute(?string $value): void
    {
        $this->attributes['vin'] = $value ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value)) ?: null : null;
    }

    public function setPlateAttribute(?string $value): void
    {
        $this->attributes['plate'] = $value ? mb_strtoupper(preg_replace('/\s+/u', '', $value)) ?: null : null;
    }

    public static function keyFor(string $ref): string
    {
        return (string) Code::key($ref);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'model_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return list<Flag> */
    public function flagList(): array
    {
        return Flag::fromList($this->flags);
    }

    /** Что вендор ждёт после приёма: своё у машины, иначе правило вендора. @return list<DocRequirement> */
    public function docsRequired(): array
    {
        $values = $this->docs_required ?: ($this->vendor?->intake_docs ?? []);

        return array_values(array_filter(array_map(fn ($v) => DocRequirement::tryFrom((string) $v), $values)));
    }

    public function ownerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'owner_party_id');
    }

    /** Покупатель, которому выдаём: физлицо из письма «продано» или из сделки CRM. */
    public function buyerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_party_id');
    }

    /** Когда выставлять счёт за хранение: своё у ТС, иначе правило вендора. */
    public function cadence(): Cadence
    {
        return $this->billing_cadence ?? $this->vendor?->billing_cadence ?? Cadence::Monthly;
    }

    /**
     * Редактор письма вендору с актом и фото: после приёма, выдачи или отказа от получения.
     * Без вендора или у своего транспорта писать некому — null.
     */
    public function reportUrl(string $act, string $back): ?string
    {
        $vendor = $this->vendor;
        if (! $vendor || ! $vendor->kind->billable()) {
            return null;
        }
        $template = match ($act) {
            'refusal' => $vendor->refusal_template_id ?? Template::park('refusal')->id,
            'release' => Template::park('release')->id,
            default => $vendor->report_template_id ?? Template::park('intake')->id,
        };

        return '/mail/new?'.http_build_query(['car' => $this->id, 'template' => $template, 'act' => $act === 'intake' ? 'intake' : 'release', 'back' => $back]);
    }

    /** Телефон покупателя для `tel:` — нормализованный или как записан. */
    public function pickupPhoneDigits(): ?string
    {
        return $this->pickup_phone ? (Phone::normalize($this->pickup_phone) ?? preg_replace('/\D+/', '', $this->pickup_phone)) : null;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'vehicle_id')->latest('issued_at')->latest('id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'vehicle_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'vehicle_id')->latest('at');
    }

    public function lastInspection(InspectionKind $kind): ?Inspection
    {
        return $this->inspections->first(fn (Inspection $i) => $i->kind === $kind);
    }

    public function docs(): HasMany
    {
        return $this->hasMany(Doc::class, 'vehicle_id')->orderBy('direction', 'desc')->orderBy('id');
    }

    /** Бумаги вендору ещё не отправлены. */
    public function docsPending(): bool
    {
        return $this->docs->contains(fn (Doc $d) => $d->isOut() && ! $d->isDone());
    }

    public function daysInTransit(): ?int
    {
        return $this->transit_started_at && $this->state === VehicleState::InTransit ? (int) $this->transit_started_at->diffInDays(now()) : null;
    }

    /** Место на площадке: заглавными, без пробелов по краям; занятое другой ТС на стоянке — ошибка формы. */
    public static function takeSpot(Yard $yard, ?string $spot, ?int $exceptId = null): ?string
    {
        $spot = $spot ? mb_strtoupper(trim($spot)) : null;
        // Места по рядам расписаны и все заняты — принимать некуда; без рядов вместимость только подсказка.
        if (! $spot && $yard->rows && ! $yard->freeSpots()) {
            throw ValidationException::withMessages(['spot' => 'На «'.$yard->name.'» свободных мест нет']);
        }
        if ($spot && self::where('yard_id', $yard->id)->where('spot', $spot)->where('state', VehicleState::Stored)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw ValidationException::withMessages(['spot' => 'Место '.$spot.' занято']);
        }

        return $spot;
    }

    /** Открытая заявка типа, если есть. */
    public function openRequest(RequestType $type): ?Request
    {
        return $this->requests->first(fn (Request $r) => $r->type === $type && $r->isOpen());
    }

    public function yard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'yard_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class, 'vehicle_id')->latest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(VehicleEvent::class, 'vehicle_id')->latest('created_at');
    }

    public function titleWithYear(): string
    {
        return trim(($this->brand?->name ?? '').' '.($this->model?->name ?? '')).($this->year ? ", {$this->year}" : '') ?: ($this->ref ?: 'ТС');
    }

    /**
     * Где ТС стояла по дням — из ленты: приём и перестановка дают площадку, погрузка — «в пути» (null).
     * Для хранения на лету: сутки в пути между площадками не считаются, ставка — по площадке того дня.
     *
     * @return list<array{day: Carbon, yard_id: ?int}>
     */
    public function yardTimeline(): array
    {
        $out = [];
        foreach ($this->events()->reorder()->whereIn('type', [EventType::Accepted, EventType::Moved, EventType::Departed])->oldest('created_at')->oldest('id')->get() as $e) {
            $p = $e->payload ?? [];
            $day = ! empty($p['day']) ? Carbon::parse($p['day'])->startOfDay() : $e->created_at->copy()->startOfDay();
            // Старые записи без yard_id — площадка нынешняя: до 22.09.2026 в ленте было только имя.
            $yardId = $e->type === EventType::Departed ? null : ($p['yard_id'] ?? $this->yard_id);
            $out[] = ['day' => $day, 'yard_id' => $yardId ? (int) $yardId : null];
        }
        usort($out, fn ($a, $b) => $a['day'] <=> $b['day']);

        return $out;
    }

    public function daysStored(): ?int
    {
        return $this->accepted_at ? (int) $this->accepted_at->diffInDays($this->released_at ?? now()) : null;
    }

    public function damages(): array
    {
        return array_values(array_filter(array_map(fn ($z) => DamageZone::labelOf($z), $this->damage_zones ?? [])));
    }

    public function log(EventType $type, ?User $by = null, array $payload = []): VehicleEvent
    {
        return $this->events()->create(['type' => $type, 'user_id' => $by?->id, 'payload' => $payload]);
    }
}
