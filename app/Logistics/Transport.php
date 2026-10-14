<?php

namespace App\Logistics;

use App\Billing\Invoice;
use App\Cars\Car;
use App\Cars\Category;
use App\Cars\Region;
use App\Media\HasPhotos;
use App\Offers\Offer;
use App\Offers\PickupState;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Search\Term;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Перевозка машины A → B (Logistics XCar, 10.10.2026). Машина — одна запись (`Cars\Car`, 14.10.2026): её продажа в CRM и
 * хранение на парковке видны отсюда через неё. Внутри региона везёт
 * партнёр (менеджер с регионом) или наш водитель, между регионами логист назначает внешнего перевозчика или нашего
 * водителя. Точки и «кто принимает» — свободным текстом. Кадры «Забрал» и «Сдал» — у самой перевозки (`photos`,
 * свойство `at`), в продажу машины они не попадают.
 */
#[Fillable(['car_id', 'scope', 'from_region_id', 'to_region_id', 'executor', 'executor_id', 'carrier', 'from_address', 'to_address',
    'receiver', 'to_yard_id', 'cost', 'ship_on', 'arrive_on', 'state', 'invoice_id', 'garage_cost_id', 'created_by', 'assigned_at', 'picked_at', 'delivered_at', 'cancelled_at'])]
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

    /** Машина перевозки. */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** Через что перевозку видно людям: продажа машины, иначе её хранение на парковке (название, кадры, ссылка). */
    public function lane(): Offer|Vehicle|null
    {
        return $this->offer ?? $this->vehicle;
    }

    /** Продажа машины в CRM. */
    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class, 'car_id', 'car_id')->withoutGlobalScopes();
    }

    /** ТС парковки — любого города: логистике город управляющего (`Park\City`) не указ. */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'car_id', 'car_id')->withoutGlobalScope(City::SCOPE);
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
        return $this->belongsTo(Yard::class, 'to_yard_id')->withoutGlobalScope(City::SCOPE);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Дата строки списка: прибытие или отправка у межрегиона, иначе когда назначили, забрали, сдали (сортировка «Когда» — по ней же). */
    public function shownAt(): ?Carbon
    {
        return match ($this->state) {
            TransportState::Delivered => $this->delivered_at,
            TransportState::Moving => $this->arrive_on ?? $this->picked_at,
            TransportState::Cancelled => $this->cancelled_at,
            default => $this->ship_on ?? $this->assigned_at ?? $this->created_at,
        };
    }

    /** Тип ТС машины — иконкой перед названием, как в таблицах CRM. */
    public function category(): ?Category
    {
        return $this->car?->category;
    }

    public function title(): string
    {
        return $this->lane()?->titleWithYear() ?? 'ТС';
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

    /**
     * Почему этот человек пока не может забрать машину (`PickupState::blocker`: оплата Альфы, документы); админ
     * отмечает за исполнителя, как «Забрал» в CRM. Забрали уже или это ТС парковки — null.
     */
    public function pickupBlocker(User $by): ?string
    {
        if ($by->isAdmin() || ! $this->offer || $this->state !== TransportState::Assigned) {
            return null;
        }

        return PickupState::blocker($this->offer);
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
    public static function openFor(Offer|Vehicle|Car $car): ?self
    {
        return self::open()->where('car_id', $car instanceof Car ? $car->id : $car->car_id)->latest('id')->first();
    }
}
