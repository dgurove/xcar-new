<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\DamageCause;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Mail\Thread;
use App\Media\HasPhotos;
use App\Park\Vehicle;
use App\Purchases\Car;
use App\Support\Demo\HidesDemo;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;

#[Fillable([
    'brand_id', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'body', 'transmission', 'drive', 'fuel',
    'engine_volume', 'engine_power', 'color', 'damage_cause', 'damage_zones', 'is_runnable', 'has_keys', 'papers',
    'incident_date', 'description', 'settlement_id', 'inspection_address', 'show_address', 'floor_price', 'publish_price',
    'asking_price', 'min_bid_price', 'min_bid_share', 'prices_include_vat', 'tags', 'tag_colors', 'bids_close_at', 'sort_weight',
    'chat_enabled', 'share_locked', 'audience_rules', 'recommended', 'vendor_id', 'claim_ref', 'insurer_deadline_at', 'car_place',
    'answer_by', 'insured_name', 'insured_phone', 'flags', 'holder', 'docs_required', 'contact_name', 'contact_email',
    'garage_allowed',
])]
class Offer extends Model implements HasMedia
{
    use HasPhotos;
    use HidesDemo;

    public const DEFAULT_SHARE = 0.6;

    protected function casts(): array
    {
        return [
            'state' => OfferState::class,
            'car_place' => CarPlace::class,
            'insurer_deadline_at' => 'date',
            'answer_by' => 'datetime',
            'flags' => 'array',
            'docs_required' => 'array',
            'body' => Body::class,
            'transmission' => Transmission::class,
            'drive' => Drive::class,
            'fuel' => Fuel::class,
            'damage_cause' => DamageCause::class,
            'papers' => Papers::class,
            'damage_zones' => 'array',
            'tags' => 'array',
            'tag_colors' => 'array',
            'show_vin' => 'bool',
            'show_address' => 'bool',
            'is_runnable' => 'bool',
            'has_keys' => 'bool',
            'prices_include_vat' => 'bool',
            'chat_enabled' => 'bool',
            'share_locked' => 'bool',
            'recommended' => 'bool',
            'garage_allowed' => 'bool',
            'audience_rules' => 'array',
            'incident_date' => 'date',
            'published_at' => 'datetime',
            'bids_close_at' => 'datetime',
            'floor_price' => 'int',
            'publish_price' => 'int',
            'asking_price' => 'int',
            'min_bid_price' => 'int',
            'min_bid_share' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** В адресе — номер, нынешний или прежний (номер меняется при первой публикации, `OfferNumber`). */
    public function resolveRouteBinding($value, $field = null)
    {
        return $field === null || $field === 'number' ? OfferNumber::find($value) : parent::resolveRouteBinding($value, $field);
    }

    public function setClaimRefAttribute(?string $value): void
    {
        $value = trim((string) $value) ?: null;
        $this->attributes['claim_ref'] = $value;
        $this->attributes['claim_ref_key'] = Code::key($value);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Машина на стоянке, если связана. */
    public function parkVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'offer_id');
    }

    /** @return list<Flag> */
    public function flagList(): array
    {
        return Flag::fromList($this->flags);
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
        // Гаражные (без суммы) — после ценовых: «Лучшая» и сортировка смотрят на цену.
        return $this->hasMany(Bid::class)->where('state', BidState::Active)->orderByRaw('amount desc nulls last')->orderBy('id');
    }

    /** Может ли человек забрать машину себе в гараж подтверждением: галка у предложения и менеджер с гаражом. */
    public function garageAllowedFor(?User $user): bool
    {
        return $this->garage_allowed && $user !== null && $user->isManager() && $user->canGarage();
    }

    /**
     * Есть ли у маршрута продажи гаражная ветка — «платим поставщику мы». Нет (Т-Страхование: машину забирают у
     * владельца по договору с ним) — платит менеджер, путём обычной сделки. Нет маршрута вовсе — выбор свободный.
     */
    public function garageBranch(): bool
    {
        $workflow = $this->stage()?->workflow ?? $this->vendor?->workflow(Track::Sale);
        if (! $workflow) {
            return true;
        }

        return Outcome::whereIn('stage_id', Stage::where('workflow_id', $workflow->id)->select('id'))->where('branch', Outcome::GARAGE)->exists();
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

    // ------------------------------------------------------------ кто видит

    /** Кто видит и с какого момента — посчитанный итог правил показа (SyncViewers). */
    public function viewers(): HasMany
    {
        return $this->hasMany(OfferViewer::class);
    }

    public function showings(): HasMany
    {
        return $this->hasMany(Showing::class);
    }

    /**
     * Черновик, в который ничего не внесли: «+ Новый» заводит его сразу, чтобы было куда грузить фото. Нет марки,
     * модели, VIN, номера убытка, цен, описания, фото, документов, писем, закупки и вывоза; вендор, подставленный с
     * прошлого черновика, работой не считается. Такой не показывается в списке, удаляется при уходе из редактора
     * (`OfferController::dropEmpty`) и ночью (`offers:prune-drafts`).
     */
    public function scopeEmptyDraft(Builder $q): Builder
    {
        return $q->where('state', OfferState::Draft)->whereNull('published_at')
            ->whereNull('brand_id')->whereNull('model_id')->whereNull('vin')->whereNull('claim_ref')->whereNull('floor_price')
            ->whereNull('asking_price')->whereNull('description')
            ->whereDoesntHave('media')->whereDoesntHave('purchaseCar')->whereDoesntHave('parkVehicle')->whereDoesntHave('positions')
            ->whereNotIn('id', Thread::whereNotNull('offer_id')->select('offer_id'))
            ->whereNotIn('id', Candidate::whereNotNull('offer_id')->select('offer_id'));
    }

    /**
     * Одна дверь видимости. Админ видит всё; модератор — заведённое им и его группой (`User::teamIds`) в любом
     * состоянии, кроме перенесённого из закупки (там цены Carcade и менеджеров); менеджер — открытые и галерею
     * из своего круга, когда его волна показа наступила; покупатель — открытые, которые ему показал его менеджер
     * (лично или группе) и которые этому менеджеру доступны; посетитель —
     * галерею; гость — ничего.
     */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user) {
            return $q->whereRaw('false');
        }
        if ($user->isAdmin()) {
            return $q;
        }
        if ($user->isModerator()) {
            return $q->whereIn('moderator_id', $user->teamIds())->whereDoesntHave('purchaseCar');
        }
        if ($user->role === Role::Manager) {
            return $q->whereIn('state', [OfferState::Open, OfferState::Gallery])
                ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->id)->where('opens_at', '<=', now()));
        }
        // Проверяющий: открытые, которые всем сразу и без исключений; без показов и менеджера.
        if ($user->role === Role::Reviewer) {
            return $q->where('state', OfferState::Open)->where(fn ($w) => $w->whereNull('audience_rules')
                ->orWhereRaw("audience_rules = '[{\"id\": null, \"type\": \"rest\", \"delay\": 0}]'::jsonb"));
        }
        if ($user->role === Role::Buyer) {
            if (! $user->manager_id) {
                return $q->whereRaw('false');
            }
            $groups = $user->groupIds();

            return $q->where('state', OfferState::Open)
                ->whereHas('showings', fn ($s) => $s->where('manager_id', $user->manager_id)
                    ->where(fn ($w) => $w->where('user_id', $user->id)->when($groups, fn ($w) => $w->orWhereIn('group_id', $groups))))
                ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->manager_id)->where('opens_at', '<=', now()));
        }

        return $q->where('state', OfferState::Gallery);
    }

    /** Страница предложения: круг видимости, а своё подтверждение открывает её в любом состоянии — без 404 после решения. */
    public function isVisibleTo(?User $user): bool
    {
        return $user?->isAdmin() || self::query()->whereKey($this->id)->visibleTo($user)->exists()
            || ($user && $this->bids()->where('user_id', $user->id)->exists());
    }

    /** CRM: что человеку можно открыть и править. Админу — всё, модератору — предложения его группы не из закупки. */
    public function isEditableBy(User $user): bool
    {
        return $user->isAdmin() || ($user->isModerator() && self::query()->whereKey($this->id)->visibleTo($user)->exists());
    }

    // ------------------------------------------------------------ подписи

    public function title(): string
    {
        return trim(($this->brand?->name ?? '').' '.($this->model?->name ?? '')) ?: 'ТС';
    }

    public function titleWithYear(): string
    {
        return $this->title().($this->year ? ", {$this->year}" : '');
    }

    /**
     * Тип ТС для иконки перед названием в таблице CRM: по кузову, иначе как у этой ТС на парковке (только если связь
     * подгружена — в списке это одна выборка), иначе по марке и модели («Газель», «КАМАЗ»); не понять — легковой.
     */
    public function category(): Category
    {
        return $this->body?->category()
            ?? ($this->relationLoaded('parkVehicle') ? $this->parkVehicle?->category : null)
            ?? Category::guess($this->title())
            ?? Category::Passenger;
    }

    /** Строка фактов под названием: год, пробег, коробка, привод. */
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

    /** ТС закупки, из которой предложение сделано по контрпредложению: её цены нужны при оценке. */
    public function purchaseCar(): HasOne
    {
        return $this->hasOne(Car::class, 'offer_id');
    }

    public function vinMasked(): ?string
    {
        if (! $this->vin) {
            return null;
        }

        return $this->show_vin ? $this->vin : substr($this->vin, 0, 5).'********'.substr($this->vin, -4);
    }

    // -------------------------------------------------------------- деньги

    /**
     * Заявленная цена — та, что менеджер считает закупочной. По умолчанию равна
     * закупочной; задана отдельно — менеджер видит её, настоящая остаётся у нас.
     */
    /** Чат по предложению с площадкой: пока оно на витрине или в галерее, а у менеджера со сделкой — и после продажи. */
    public function chatOpenFor(?User $user): bool
    {
        return $user && $user->canChat() && $this->chat_enabled
            && ($this->state->isPublic() || $this->state->acceptsInterest() || $this->deal()->where('buyer_id', $user->id)->exists());
    }

    /** Номер договора лизинга (ДЛ) — у предложений лизинговых вендоров в claim_ref; его видят и менеджеры. */
    public function leaseRef(): ?string
    {
        $vendor = $this->vendor_id ? Vendor::badges()->get($this->vendor_id) : null;

        return $vendor?->kind === Kind::Leasing ? ($this->claim_ref ?: null) : null;
    }

    /**
     * Заявленная — единственная цена «от», которую видит xcar: вписанная руками, иначе закупочная, округлённая вверх
     * до тысячи (967 619 → 968 000). Сама закупочная на сайте не бывает нигде и ни у кого, даже в тексте «Поделиться».
     */
    public function declaredPrice(): ?int
    {
        return $this->publish_price ?? self::declaredFrom($this->floor_price);
    }

    public static function declaredFrom(?int $floor): ?int
    {
        return $floor ? (int) (ceil($floor / 1000) * 1000) : null;
    }

    /** Нижняя граница подтверждения: заданная руками или по доле между заявленной и продажной. */
    public function minBid(): ?int
    {
        if ($this->min_bid_price) {
            return $this->min_bid_price;
        }
        $from = $this->declaredPrice();
        if ($this->asking_price && $from && $this->asking_price > $from) {
            $share = $this->min_bid_share ?? self::DEFAULT_SHARE;

            return (int) round($from + ($this->asking_price - $from) * $share, -3);
        }

        return $this->asking_price;
    }

    /** Приём подтверждений — не состояние, а срок: открыт, пока не прошёл bids_close_at (пустой — бессрочно). */
    public function bidsOpen(): bool
    {
        return $this->state->acceptsBids() && (! $this->bids_close_at || $this->bids_close_at->isFuture());
    }

    /** Открыт, но срок прошёл: приём закрыт сам собой, сдвиг срока вперёд открывает заново. */
    public function closed(): bool
    {
        return $this->state->acceptsBids() && $this->bids_close_at?->isPast() === true;
    }

    /** Поиск в списках CRM: номер (и прежний), VIN, марка, модель. */
    public function scopeSearch(Builder $q, string $term): Builder
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $digits = trim($term);

        return $q->where(fn ($w) => $w->whereRaw('cast(number as text) like ?', [$like])->orWhereRaw('lower(vin) like ?', [$like])
            // Прежний номер (до первой публикации или до перенумерации) — точным совпадением.
            ->when(ctype_digit($digits), fn ($w) => $w->orWhereIn('id', DB::table('offer_number_aliases')->where('number', $digits)->select('offer_id')))
            ->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(name_ru) like ?', [$like]))
            ->orWhereHas('model', fn ($m) => $m->whereRaw('lower(name) like ?', [$like])));
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
