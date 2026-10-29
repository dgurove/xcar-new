<?php

namespace App\Purchases;

use App\Cars\OnCar;
use App\Cars\Region;
use App\Cars\Settlement;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Search\CarWords;
use App\Search\Term;
use App\Support\Surface;
use App\Support\Units;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'purchase_id', 'dl', 'ref', 'brand_id', 'model_id', 'brand_raw', 'model_raw', 'year', 'vin', 'mileage', 'transmission', 'fuel',
    'engine_volume', 'engine_power', 'color', 'steering', 'keys', 'settlement_id', 'address', 'city', 'condition', 'encumbrance',
    'fssp', 'vehicle_type', 'kind', 'stage', 'price_revalued', 'price_listing', 'price_final', 'site_url', 'cloud_url', 'cloud_leftovers',
    'specs_state', 'specs_error', 'specs_at', 'photos_state', 'photos_error', 'photos_at', 'photos_count', 'locked_fields',
    'is_published', 'description', 'share_locked', 'announced_at',
])]
class Car extends Model
{
    use OnCar;

    protected $table = 'purchase_cars';

    /** Машина (`Cars\Car`): ДЛ, VIN, марка, характеристики и фото лота — у неё. */
    protected $with = ['car'];

    protected static function booted(): void
    {
        // История машины (29.10.2026): лот появился в закупке, наша цена было → стало — из любой двери (файл, оценка
        // строкой, правка лота).
        static::created(fn (self $car) => $car->log(OfferEventType::Created, auth()->user(), ['dl' => $car->dl]));
        static::updated(function (self $car) {
            if ($car->wasChanged('price_final')) {
                $car->log(OfferEventType::Updated, auth()->user(), ['changes' => ['price_final' => [$car->getOriginal('price_final'), $car->price_final]]]);
            }
        });
    }

    /** Строка истории машины с этого лота (`car_events`, `purchase_car_id`): номер закупки — в тексте строки. */
    public function log(OfferEventType $type, ?User $user = null, array $payload = []): OfferEvent
    {
        return OfferEvent::create(['car_id' => $this->car_id, 'purchase_car_id' => $this->id, 'type' => $type, 'user_id' => $user?->id,
            'payload' => ['purchase' => $this->purchase->number] + $payload]);
    }

    /** ДЛ лота — номер машины (`claim_ref`), как номер убытка у страховой. */
    public static function carAliases(): array
    {
        return ['dl' => 'claim_ref'];
    }

    protected function casts(): array
    {
        return [
            'kind' => Kind::class,
            'specs_state' => ImportState::class, 'photos_state' => ImportState::class,
            'specs_at' => 'datetime', 'photos_at' => 'datetime', 'cloud_leftovers' => 'array', 'locked_fields' => 'array',
            'is_published' => 'bool', 'share_locked' => 'bool', 'announced_at' => 'datetime', 'fssp' => 'bool',
        ];
    }

    /**
     * Поиск машины закупки — CRM и сайт одной дверью: VIN, марка и модель (как в файле поставщика и по справочнику, по
     * словам). В CRM ещё ДЛ куском; на сайте ДЛ нет — номер «№ 12» (`shownNumber`).
     */
    public function scopeSearch(Builder $q, string|Term $term, bool $crm = false): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $number = preg_match('/^\s*№?\s*(\d{1,9})\s*$/u', $t->raw, $m) ? (int) $m[1] : null;
        // «№ 12» — так номер показан на экране: ищется он один, без кусков VIN и марки (иначе «12» тянуло десятки машин).
        if (! $crm && $number && str_contains($t->raw, '№')) {
            return $q->where('purchase_cars.ref', $number);
        }

        return $q->where(fn ($w) => $w
            ->when($crm, fn ($w) => $w->orWhereHas('car', fn ($c) => $c->whereRaw('lower(cars.claim_ref) like ?', [$t->contains()])))
            ->when(! $crm && $number, fn ($w) => $w->orWhere('purchase_cars.ref', $number))
            ->when($t->vin, fn ($w, $vin) => $w->orWhereHas('car', fn ($c) => $c->where('cars.vin', 'like', Term::like($vin))))
            ->orWhere(fn ($all) => CarWords::apply($all, $t, fn ($one, $word, $like) => $one
                ->orWhereRaw('lower(purchase_cars.brand_raw) like ?', [$like])
                ->orWhereRaw('lower(purchase_cars.model_raw) like ?', [$like]))));
    }

    public function getRouteKeyName(): string
    {
        return 'ref';
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /**
     * Предложение этой машины: ушла в предложения по контрпредложению поставщика (или о ней уже знали из писем) — из
     * закупки она с этого момента исключена.
     */
    public function offer(): HasOne
    {
        return $this->hasOne(\App\Offers\Offer::class, 'car_id', 'car_id')->withoutGlobalScopes();
    }

    /**
     * Цены менеджеров за машину лота — подтверждения вида «закупка» в общей таблице `bids` (29.10.2026: цена менеджера
     * за машину одна сущность, что в закупке, что в продаже). Предложения у них нет — из подтверждений продажи их
     * прячет глобальное условие `Bid::SALE`, здесь оно снято.
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class, 'purchase_car_id')->withoutGlobalScope(Bid::SALE)->orderByDesc('amount');
    }

    /** Номер для людей: в CRM — ДЛ, на xcar — номер машины в закупке «№ 12» (ДЛ на xcar нет, владелец 10.10.2026). */
    public function shownNumber(): string
    {
        if (! Surface::inCrm()) {
            return '№ '.$this->ref;
        }

        return str_starts_with(mb_strtoupper((string) $this->dl), 'ДЛ') ? (string) $this->dl : 'ДЛ '.$this->dl;
    }

    public function title(): string
    {
        return $this->carRecord()->title($this->brand_raw, $this->model_raw) ?: 'ТС';
    }

    public function titleWithYear(): string
    {
        return $this->carRecord()->withYear($this->title());
    }

    public function facts(): array
    {
        return array_values(array_filter([
            Units::km($this->mileage),
            $this->transmission?->label(),
            $this->fuel?->label(),
            Units::liters($this->engine_volume),
            Units::hp($this->engine_power),
            $this->color,
        ]));
    }

    /** Характеристики подписями для страницы машины: только заполненные, порядок как на витрине. */
    public function factsList(): array
    {
        return array_filter([
            'Тип' => $this->kind->label(), 'Год' => $this->year, 'Пробег' => Units::km($this->mileage),
            'Коробка' => $this->transmission?->label(), 'Топливо' => $this->fuel?->label(), 'Объём' => Units::liters($this->engine_volume),
            'Мощность' => Units::hp($this->engine_power), 'Цвет' => $this->color, 'Руль' => $this->steering, 'Ключи' => $this->keys,
            // ДЛ — только в CRM (номеров убытка и ДЛ на xcar.ru нет нигде, 10.10.2026); на сайте номер — в шапке («№ 12»).
            'Состояние' => $this->condition, 'VIN' => $this->vin, 'Номер' => Surface::inCrm() ? $this->dl : null, 'Где' => $this->settlement?->title() ?? $this->city ?? $this->address, 'Обременения' => Surface::inCrm() ? $this->encumbrance : \App\Support\VendorNames::strip($this->encumbrance),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Что из карточки закупки уходит в предложение сверх одноимённых полей: VIN открыт (в закупке его видели все), адрес
     * осмотра — скрыт, как у любого предложения (07.10.2026, владелец: «по умолчанию должен быть скрыт»), ключи — флагом,
     * «ФССП» — меткой, а состояние, руль, ключи словом, обременения и город, которого нет в справочнике, — строками
     * описания (своего поля у предложения для них нет).
     */
    public function offerExtras(): array
    {
        $lines = array_filter([
            'Состояние' => $this->condition, 'Руль' => $this->steering, 'Ключи' => $this->keys, 'Обременения' => $this->encumbrance,
            'Город' => $this->settlement_id ? null : $this->city,
        ], fn ($v) => $v !== null && $v !== '');
        $text = implode("\n", array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($lines), $lines));

        return array_filter([
            'show_vin' => true,
            'has_keys' => $this->keys === null || $this->keys === '' ? null : ! in_array(mb_strtolower(trim($this->keys)), ['нет', '0', 'отсутствуют'], true),
            'description' => trim(implode("\n\n", array_filter([$this->description, $text]))) ?: null,
            'tags' => $this->fssp ? ['Ограничения ФССП'] : null,
        ], fn ($v) => $v !== null);
    }

    public function isLocked(string $field): bool
    {
        return in_array($field, $this->locked_fields ?? [], true);
    }

    public function offerOf(?User $user): ?Bid
    {
        return $user ? $this->bids->first(fn (Bid $o) => $o->user_id === $user->id && in_array($o->state, [BidState::Active, BidState::Accepted], true)) : null;
    }

    /**
     * Город машины: областной город из карточки Carcade («Пермь»), а не деревня из адреса стоянки («д. Ванюки») —
     * менеджеры ищут и фильтруют по городу (владелец 07.10.2026). Адрес — когда города в карточке нет или он не в
     * справочнике.
     */
    public function townId(): ?int
    {
        // Одноимённые города в разных регионах (Благовещенск Амурский и Башкирский) — сначала регион из адреса.
        $region = $this->address ? Region::inText($this->address) : null;
        // С регионом справочник ищет и деревни («Москва» в Московской области) — берём оттуда только город.
        $inRegion = $this->city && $region ? Settlement::named($this->city, $region) : null;
        if ($inRegion && (int) Settlement::whereKey($inRegion['id'])->value('rank') > Settlement::TOWN) {
            $inRegion = null;
        }
        $town = $inRegion ?? ($this->city ? Settlement::named($this->city) : null);

        return $town['id'] ?? Settlement::inAddress($this->address)['id'] ?? null;
    }

    /** Место не ставили руками и оно пустое или деревня — его можно заменить городом (`townId`). */
    public function townReplaceable(): bool
    {
        return ! $this->isLocked('settlement_id')
            && (! $this->settlement_id || (int) Settlement::whereKey($this->settlement_id)->value('rank') > Settlement::TOWN);
    }

    /** Активные и выбранные цены из уже загруженной связи, без запроса. */
    public function activeOfferList(): Collection
    {
        return $this->bids->whereIn('state', [BidState::Active, BidState::Accepted])->values();
    }

    /**
     * Оценки машины для цены продажи: последняя цена каждого, кто её называл, в любом состоянии («не выбрана» и отозванная
     * без замены тоже оценка), по сумме вниз. Цена, которую человек сам заменил новой, не дублируется.
     */
    public function latestOfferList(): Collection
    {
        return $this->bids->groupBy('user_id')->map(fn (Collection $mine) => $mine->sortByDesc('id')->first())->sortByDesc('amount')->values();
    }

    public function bestOffer(): ?Bid
    {
        return $this->bids->firstWhere('state', BidState::Accepted) ?? $this->bids->firstWhere('state', BidState::Active);
    }

    public static function nextRef(): int
    {
        return (int) DB::selectOne("select nextval('purchase_car_refs') as n")->n;
    }
}
