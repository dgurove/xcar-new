<?php

namespace App\Park;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\DamageZone;
use App\Media\HasPhotos;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

#[Fillable(['ref', 'vin', 'brand_id', 'model_id', 'year', 'plate', 'color', 'client_id', 'state', 'yard_id', 'accepted_at', 'released_at', 'damage_zones', 'damage_note', 'notes', 'offer_id'])]
class Vehicle extends Model implements HasMedia
{
    use HasPhotos;

    protected $table = 'park_vehicles';

    protected function casts(): array
    {
        return ['state' => VehicleState::class, 'damage_zones' => 'array', 'accepted_at' => 'datetime', 'released_at' => 'datetime', 'year' => 'int'];
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
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $ref));
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'model_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
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
        return trim(implode(' ', array_filter([$this->brand?->name, $this->model?->name, $this->year ? ', '.$this->year : null]))) ?: ($this->ref ?: 'Машина');
    }

    public function titleWithYear(): string
    {
        return trim(($this->brand?->name ?? '').' '.($this->model?->name ?? '')).($this->year ? ", {$this->year}" : '') ?: ($this->ref ?: 'Машина');
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
