<?php

namespace App\Purchases;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Fuel;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Media\HasPhotos;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;

#[Fillable([
    'purchase_id', 'dl', 'ref', 'brand_id', 'model_id', 'brand_raw', 'model_raw', 'year', 'vin', 'mileage', 'transmission', 'fuel',
    'engine_volume', 'engine_power', 'color', 'steering', 'keys', 'settlement_id', 'address', 'city', 'condition', 'encumbrance',
    'fssp', 'vehicle_type', 'kind', 'stage', 'price_revalued', 'price_listing', 'site_url', 'cloud_url', 'cloud_leftovers',
    'specs_state', 'specs_error', 'specs_at', 'photos_state', 'photos_error', 'photos_at', 'photos_count', 'locked_fields',
    'is_published', 'description',
])]
class Car extends Model implements HasMedia
{
    use HasPhotos;

    protected $table = 'purchase_cars';

    protected function casts(): array
    {
        return [
            'kind' => Kind::class, 'transmission' => Transmission::class, 'fuel' => Fuel::class,
            'specs_state' => ImportState::class, 'photos_state' => ImportState::class,
            'specs_at' => 'datetime', 'photos_at' => 'datetime', 'cloud_leftovers' => 'array', 'locked_fields' => 'array',
            'is_published' => 'bool', 'fssp' => 'bool', 'year' => 'int',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ref';
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'model_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'car_id')->orderByDesc('amount');
    }

    public function activeOffers(): HasMany
    {
        return $this->offers()->whereIn('state', [OfferState::Active, OfferState::Chosen]);
    }

    public function title(): string
    {
        return trim(($this->brand?->name ?? $this->brand_raw ?? '').' '.($this->model?->name ?? $this->model_raw ?? '')) ?: 'Машина';
    }

    public function titleWithYear(): string
    {
        return $this->title().($this->year ? ", {$this->year}" : '');
    }

    public function facts(): array
    {
        return array_values(array_filter([
            $this->mileage !== null ? number_format($this->mileage, 0, '', ' ').' км' : null,
            $this->transmission?->label(),
            $this->fuel?->label(),
            $this->engine_volume ? number_format($this->engine_volume / 1000, 1, ',', '').' л' : null,
            $this->engine_power ? $this->engine_power.' л. с.' : null,
            $this->color,
        ]));
    }

    public function isLocked(string $field): bool
    {
        return in_array($field, $this->locked_fields ?? [], true);
    }

    public function offerOf(?User $user): ?Offer
    {
        return $user ? $this->offers->first(fn (Offer $o) => $o->user_id === $user->id && in_array($o->state, [OfferState::Active, OfferState::Chosen], true)) : null;
    }

    /** Активные и выбранные цены из уже загруженной связи, без запроса. */
    public function activeOfferList(): Collection
    {
        return $this->offers->whereIn('state', [OfferState::Active, OfferState::Chosen])->values();
    }

    public function bestOffer(): ?Offer
    {
        return $this->offers->firstWhere('state', OfferState::Chosen) ?? $this->offers->firstWhere('state', OfferState::Active);
    }

    public static function nextRef(): int
    {
        return (int) DB::selectOne("select nextval('purchase_car_refs') as n")->n;
    }
}
