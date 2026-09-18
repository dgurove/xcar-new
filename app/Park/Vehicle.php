<?php

namespace App\Park;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\DamageZone;
use App\Mail\Extraction\Code;
use App\Media\HasPhotos;
use App\Offers\Flag;
use App\Offers\Offer;
use App\Users\User;
use App\Vendors\DocRequirement;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

#[Fillable(['ref', 'vin', 'brand_id', 'model_id', 'year', 'plate', 'color', 'category', 'oversize', 'vendor_id', 'state', 'yard_id', 'accepted_at', 'released_at', 'damage_zones', 'damage_note', 'notes', 'offer_id',
    'contact_name', 'contact_phone', 'flags', 'docs_required', 'docs_done', 'value'])]
class Vehicle extends Model implements HasMedia
{
    use HasPhotos;

    protected $table = 'park_vehicles';

    protected function casts(): array
    {
        return ['state' => VehicleState::class, 'category' => Category::class, 'oversize' => 'bool', 'damage_zones' => 'array', 'flags' => 'array', 'docs_required' => 'array', 'docs_done' => 'array',
            'accepted_at' => 'datetime', 'released_at' => 'datetime', 'year' => 'int'];
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

    public function docDone(DocRequirement $doc): bool
    {
        return in_array($doc->value, $this->docs_done ?? [], true);
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

    public function title(): string
    {
        return trim(implode(' ', array_filter([$this->brand?->name, $this->model?->name, $this->year ? ', '.$this->year : null]))) ?: ($this->ref ?: 'ТС');
    }

    public function titleWithYear(): string
    {
        return trim(($this->brand?->name ?? '').' '.($this->model?->name ?? '')).($this->year ? ", {$this->year}" : '') ?: ($this->ref ?: 'ТС');
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
