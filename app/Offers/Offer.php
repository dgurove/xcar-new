<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\DamageCause;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Mail\Extraction\Code;
use App\Media\HasPhotos;
use App\Users\User;
use App\Workflow\Insurer;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;

#[Fillable([
    'brand_id', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'body', 'transmission', 'drive', 'fuel',
    'engine_volume', 'engine_power', 'color', 'damage_cause', 'damage_zones', 'is_runnable', 'has_keys', 'papers',
    'incident_date', 'description', 'settlement_id', 'inspection_address', 'floor_price', 'repair_estimate',
    'asking_price', 'min_bid_price', 'min_bid_share', 'prices_include_vat', 'tags', 'bids_close_at', 'sort_weight',
    'chat_enabled', 'insurer_id', 'claim_ref', 'insurer_deadline_at', 'car_place',
])]
class Offer extends Model implements HasMedia
{
    use HasPhotos;

    public const DEFAULT_SHARE = 0.6;

    protected function casts(): array
    {
        return [
            'state' => OfferState::class,
            'car_place' => CarPlace::class,
            'insurer_deadline_at' => 'date',
            'body' => Body::class,
            'transmission' => Transmission::class,
            'drive' => Drive::class,
            'fuel' => Fuel::class,
            'damage_cause' => DamageCause::class,
            'papers' => Papers::class,
            'damage_zones' => 'array',
            'tags' => 'array',
            'show_vin' => 'bool',
            'is_runnable' => 'bool',
            'has_keys' => 'bool',
            'prices_include_vat' => 'bool',
            'chat_enabled' => 'bool',
            'incident_date' => 'date',
            'published_at' => 'datetime',
            'bids_close_at' => 'datetime',
            'floor_price' => 'int',
            'repair_estimate' => 'int',
            'asking_price' => 'int',
            'min_bid_price' => 'int',
            'min_bid_share' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function setClaimRefAttribute(?string $value): void
    {
        $value = trim((string) $value) ?: null;
        $this->attributes['claim_ref'] = $value;
        $this->attributes['claim_ref_key'] = Code::key($value);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class)->latest();
    }

    /** Где оффер стоит на ветке маршрута; null — маршрута на ветке нет. */
    public function position(Track $track = Track::Sale): ?Position
    {
        $positions = $this->relationLoaded('positions') ? $this->positions : $this->positions()->with('stage.block', 'stage.exits.to')->get();

        return $positions->firstWhere('track', $track);
    }

    public function stage(Track $track = Track::Sale): ?Stage
    {
        return $this->position($track)?->stage;
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

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class)->latest();
    }

    public function activeBids(): HasMany
    {
        return $this->hasMany(Bid::class)->where('state', BidState::Active)->orderByDesc('amount');
    }

    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class)->latest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(OfferEvent::class)->latest();
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class)->where('state', DealState::Active);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    // ------------------------------------------------------------ подписи

    public function title(): string
    {
        return trim(($this->brand?->name ?? '').' '.($this->model?->name ?? '')) ?: 'Машина';
    }

    public function titleWithYear(): string
    {
        return $this->title().($this->year ? ", {$this->year}" : '');
    }

    /** Строка фактов под названием: год · пробег · коробка · привод. */
    public function facts(): array
    {
        return array_values(array_filter([
            $this->year ? $this->year.' г.' : null,
            $this->mileage !== null ? number_format($this->mileage, 0, '', ' ').' км' : null,
            $this->transmission?->label(),
            $this->drive?->label(),
            $this->fuel?->label(),
            $this->engine_volume ? number_format($this->engine_volume / 1000, 1, ',', '').' л' : null,
        ]));
    }

    public function vinMasked(): ?string
    {
        if (! $this->vin) {
            return null;
        }

        return $this->show_vin ? $this->vin : substr($this->vin, 0, 5).'********'.substr($this->vin, -4);
    }

    // -------------------------------------------------------------- деньги

    /** Нижняя граница подтверждения: заданная руками или по доле между закупочной и продажной. */
    public function minBid(): ?int
    {
        if ($this->min_bid_price) {
            return $this->min_bid_price;
        }
        if ($this->asking_price && $this->floor_price && $this->asking_price > $this->floor_price) {
            $share = $this->min_bid_share ?? self::DEFAULT_SHARE;

            return (int) round($this->floor_price + ($this->asking_price - $this->floor_price) * $share, -3);
        }

        return $this->asking_price;
    }

    public function bidsOpen(): bool
    {
        return $this->state->acceptsBids() && (! $this->bids_close_at || $this->bids_close_at->isFuture());
    }

    // -------------------------------------------------------------- метки

    public function isGallery(): bool
    {
        return $this->state === OfferState::Gallery;
    }

    /** Опубликован меньше суток назад. */
    public function isFresh(): bool
    {
        return $this->published_at !== null && $this->published_at->gt(now()->subDay());
    }

    /** До закрытия приёма меньше суток. */
    public function isEndingSoon(): bool
    {
        $left = $this->secondsLeft();

        return $left !== null && $left > 0 && $left < 86400;
    }

    /** Секунд до закрытия приёма; null — приём не ограничен или закрыт. */
    public function secondsLeft(): ?int
    {
        if (! $this->bids_close_at || ! $this->state->acceptsBids()) {
            return null;
        }

        return (int) max(0, now()->diffInSeconds($this->bids_close_at, false));
    }

    public function isFavoriteOf(?User $user): bool
    {
        return $user && $this->favorites->contains('user_id', $user->id);
    }

    public function log(OfferEventType $type, ?User $user = null, array $payload = []): OfferEvent
    {
        return $this->events()->create(['type' => $type, 'user_id' => $user?->id, 'payload' => $payload]);
    }
}
