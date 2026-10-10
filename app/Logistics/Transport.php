<?php

namespace App\Logistics;

use App\Billing\Invoice;
use App\Cars\Region;
use App\Media\HasPhotos;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Search\Term;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Перевозка машины A → B (Logistics XCar, 10.10.2026). Машина — предложение CRM или ТС парковки. Внутри региона везёт
 * партнёр (менеджер с регионом) или наш водитель, между регионами логист назначает внешнего перевозчика или нашего
 * водителя. Точки и «кто принимает» — свободным текстом. Кадры «Забрал» и «Сдал» — у самой перевозки (`photos`,
 * свойство `at`), в продажу машины они не попадают.
 */
#[Fillable(['offer_id', 'vehicle_id', 'scope', 'from_region_id', 'to_region_id', 'executor', 'executor_id', 'carrier', 'from_address', 'to_address',
    'receiver', 'to_yard_id', 'cost', 'ship_on', 'arrive_on', 'state', 'invoice_id', 'garage_cost_id', 'note', 'created_by', 'assigned_at', 'picked_at', 'delivered_at', 'cancelled_at'])]
class Transport extends Model implements HasMedia
{
    use HasPhotos;

    protected function casts(): array
    {
        return [
            'scope' => Scope::class,
            'executor' => Executor::class,
            'state' => TransportState::class,
            'cost' => 'decimal:2',
            'ship_on' => 'date',
            'arrive_on' => 'date',
            'assigned_at' => 'datetime',
            'picked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function fromRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'from_region_id');
    }

    public function toRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'to_region_id');
    }

    public function executorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    public function toYard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'to_yard_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Машина перевозки: предложение или ТС парковки. */
    public function car(): Offer|Vehicle|null
    {
        return $this->offer ?? $this->vehicle;
    }

    public function title(): string
    {
        return $this->car()?->titleWithYear() ?? 'ТС';
    }

    /** Госномер — у ТС парковки; у предложения его нет. */
    public function plate(): ?string
    {
        return $this->vehicle?->plate;
    }

    /** Кто везёт словом: «Сахиб М.», «ТК Деловые линии»; пусто — ещё не назначили. */
    public function executorName(): ?string
    {
        return $this->executor === Executor::Carrier ? $this->carrier : $this->executorUser?->shortName();
    }

    /** «Москва → Санкт-Петербург»; внутри региона — один регион. */
    public function regions(): ?string
    {
        $from = $this->fromRegion?->name;
        $to = $this->toRegion?->name;
        if ($this->scope === Scope::Local || ! $to || $to === $from) {
            return $from;
        }

        return ($from ?? '?').' → '.$to;
    }

    public function isOpen(): bool
    {
        return in_array($this->state, [TransportState::New, TransportState::Assigned, TransportState::Moving], true);
    }

    /** Кадры этапа: `pickup` — «Забрал», `delivery` — «Сдал». */
    public function photosAt(string $at)
    {
        return $this->photos()->filter(fn (Media $m) => $m->getCustomProperty('at') === $at)->values();
    }

    /** Ведёт ли человек эту перевозку как исполнитель. */
    public function isExecutedBy(User $user): bool
    {
        return $this->executor_id === $user->id && $this->executor !== Executor::Carrier;
    }

    /**
     * Кто что видит: админ и логист — всё, водитель и партнёр — назначенные им. Логист видит и местные: он отвечает за
     * всю логистику, а назначает их админ из CRM.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->seesAllTransports() ? $q : $q->where('executor_id', $user->id)->whereIn('executor', [Executor::Partner, Executor::Driver]);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('state', [TransportState::New, TransportState::Assigned, TransportState::Moving]);
    }

    /** Поиск по машине: марка, модель, VIN, госномер, номер предложения; и по адресам. */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = Term::like($t->raw);

        return $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $o->search($t))
            ->orWhereHas('vehicle', fn ($v) => $v->search($t))
            ->orWhere('from_address', 'ilike', $like)->orWhere('to_address', 'ilike', $like)->orWhere('carrier', 'ilike', $like));
    }

    /** Открытая перевозка машины — одна: вторую не заводим, пока эта не доставлена или не отменена. */
    public static function openFor(Offer|Vehicle $car): ?self
    {
        return self::open()->where($car instanceof Offer ? 'offer_id' : 'vehicle_id', $car->id)->latest('id')->first();
    }
}
