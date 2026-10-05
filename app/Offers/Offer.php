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
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;

#[Fillable([
    'brand_id', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'body', 'transmission', 'drive', 'fuel',
    'engine_volume', 'engine_power', 'color', 'damage_cause', 'damage_zones', 'is_runnable', 'has_keys', 'papers',
    'incident_date', 'description', 'settlement_id', 'inspection_address', 'show_address', 'floor_price', 'owner_price', 'value', 'publish_price',
    'asking_price', 'min_bid_price', 'min_bid_share', 'prices_include_vat', 'tags', 'tag_colors', 'bids_close_at', 'sort_weight',
    'chat_enabled', 'share_locked', 'audience_rules', 'recommended', 'vendor_id', 'claim_ref', 'insurer_deadline_at', 'car_place',
    'answer_by', 'insured_name', 'insured_phone', 'flags', 'holder', 'docs_required', 'contact_name', 'contact_email',
    'garage_allowed', 'evacuator_id', 'evacuation_to',
])]
class Offer extends Model implements HasMedia
{
    use HasPhotos;
    use HidesDemo;

    public const DEFAULT_SHARE = 0.6;

    protected static function booted(): void
    {
        // Тип ТС колонкой — для фильтра; марка сменилась — прежняя связь уже не та.
        static::saving(function (self $o) {
            // У продажи свой вендор: «АльфаСтрахование СПб» парковки здесь — «АльфаСтрахование».
            if ($o->isDirty('vendor_id')) {
                $o->vendor_id = Vendor::forCrm($o->vendor_id);
            }
            if ($o->vehicle_category === null || $o->isDirty(['body', 'brand_id', 'model_id'])) {
                foreach (['brand' => 'brand_id', 'model' => 'model_id'] as $rel => $col) {
                    if ($o->isDirty($col)) {
                        $o->unsetRelation($rel);
                    }
                }
                $o->vehicle_category = $o->guessCategory()->value;
            }
        });
        // Машина одна: вписали номер убытка или VIN машины, что стоит на парковке, — предложение связывается с ней, и
        // фото с документами становятся общими (`Park\Sale::adopt`), а не живут двумя машинами.
        static::saved(fn (self $o) => $o->wasChanged(['claim_ref_key', 'vin']) || ($o->wasRecentlyCreated && ($o->claim_ref_key || $o->vin)) ? Sale::adopt($o) : null);
        // Продажа кончилась (в архив, снят) — ТС парковки отвязывается, всё её остаётся у неё; вернули из архива — связь
        // возвращается сама, если ТС та же и свободна (владелец 05.10.2026: «удаление предложения отменяет продажу, а
        // парковку не трогает»).
        static::saved(function (self $o) {
            if (! $o->wasChanged('state')) {
                return;
            }
            in_array($o->state, [OfferState::Archived, OfferState::Cancelled], true) ? Sale::end($o) : Sale::adopt($o);
        });
        // Черновик удаляют (бросили, «Отменить», ночная уборка) — отвязать по-человечески, с записью в истории ТС.
        static::deleting(fn (self $o) => Sale::end($o));
    }

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
            'slot_at' => 'datetime',
            'floor_price' => 'int',
            'value' => 'int',
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

    /** Кадры и документы предложения — свои и ТС парковки, новые ложатся к ТС (`SaleMedia`). */
    public function media(): MorphMany
    {
        return new SaleMedia($this->newRelatedInstance($this->getMediaModel())->newQuery(), $this, 'model_type', 'model_id', $this->getKeyName());
    }

    /** Только свои файлы предложения: при удалении черновика файлы ТС остаются у ТС. */
    public function ownMedia(): MorphMany
    {
        return $this->morphMany($this->getMediaModel(), 'model');
    }

    public function deleteAllMedia(): self
    {
        $this->ownMedia()->cursor()->each(fn ($media) => $media->delete());

        return $this;
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

    /** Строка гаража: у кого машина «на подготовке» (менеджер продаёт сам). */
    public function garageCar(): HasOne
    {
        return $this->hasOne(\App\Garage\Car::class);
    }

    /** Кто вывозит; пусто при живом вывозе — мы. */
    public function evacuator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evacuator_id');
    }

    /**
     * У кого ТС стоит «у менеджера» (`Destination::Keeper`): машина в гараже — у её менеджера, кто бы её ни вёз (05.10.2026,
     * Бородин: везёт наш Алексей, а стоять ей у Бородина), иначе — у вывозчика.
     */
    public function keeper(): ?User
    {
        $garage = $this->relationLoaded('garageCar') ? $this->garageCar : $this->garageCar()->with('manager')->first();

        return $garage?->manager ?? $this->evacuator;
    }

    /** ТС уже забрали: вывоз дошёл до места (у менеджера, у нас, на парковке) — кто и куда, менять поздно. */
    public function pickedUp(): bool
    {
        return in_array($this->position(Track::Service)?->stage->car_place, [CarPlace::Keeper, CarPlace::WithUs, CarPlace::Ours], true);
    }

    /**
     * Можно ли при принятии подтверждения выбрать, кто забирает ТС (04.10.2026): у вендора есть вывоз, ТС ещё у
     * владельца — не забрана и не стоит у нас на парковке.
     */
    public function pickupChoosable(): bool
    {
        return (bool) $this->vendor?->workflow(Track::Service)?->is_active && ! $this->pickedUp()
            && $this->parkVehicle?->state !== VehicleState::Stored
            && ! in_array($this->state, [OfferState::Garage, OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true);
    }

    /** Куда вывозят: не назначено — на парковку, как было до 04.10.2026. */
    public function pickupDestination(): Destination
    {
        return Destination::tryFrom((string) $this->evacuation_to) ?? Destination::Yard;
    }

    /** Чем ветка маршрута выбирает исходы (`Outcome::fits`): на продаже — идущая сделка, на вывозе — куда везём. */
    public function branchFor(Track $track): Deal|Destination|null
    {
        return $track === Track::Sale ? $this->deal : $this->pickupDestination();
    }

    /**
     * Работает ли менеджер с этой ТС: держит её в гараже, вывозит или ведёт по ней сделку (идущую или закрытую).
     * Им открываются отмеченные документы (`Media\ForManagers`) и кадры.
     */
    public function worksWith(User $user): bool
    {
        return $this->evacuator_id === $user->id
            || \App\Garage\Car::where('offer_id', $this->id)->where('manager_id', $user->id)->exists()
            || Deal::where('offer_id', $this->id)->where('buyer_id', $user->id)->whereIn('state', [DealState::Active, DealState::Done])->exists();
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
    /**
     * Вывозы мимо парковки (к менеджеру, к нам), что ещё живы: менеджеру — порученные ему, сотруднику — все с менеджером.
     * Продали и выдали, сняли — из списка ушла.
     */
    public function scopePickupsOf(Builder $q, User $user): Builder
    {
        return $q->whereIn('evacuation_to', [Destination::Keeper->value, Destination::Ours->value])
            ->whereNotIn('state', [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived])
            ->whereHas('positions', fn ($p) => $p->where('track', Track::Service))
            ->when($user->isAdmin(), fn ($q) => $q->whereNotNull('evacuator_id'), fn ($q) => $q->where('evacuator_id', $user->id)
                // Забирает ТС по своей сделке — это её шаг, а не поручение: живёт на странице сделки (`Handover`).
                ->whereDoesntHave('deal', fn ($d) => $d->where('buyer_id', $user->id)));
    }

    /** Ход ответственного за вывоз — «забрать»: на текущем этапе вывоза есть его кнопка той ветки, куда везём. Одним запросом. */
    public function scopeAwaitingPickup(Builder $q): Builder
    {
        return $q->whereHas('positions', fn ($p) => $p->where('track', Track::Service)
            ->whereHas('stage.exits', fn ($e) => $e->where('actor', 'keeper')->whereColumn('workflow_exits.branch', 'offers.evacuation_to')));
    }

    public function scopeEmptyDraft(Builder $q): Builder
    {
        return $q->where('state', OfferState::Draft)->whereNull('published_at')
            ->whereNull('brand_id')->whereNull('model_id')->whereNull('vin')->whereNull('claim_ref')->whereNull('floor_price')
            ->whereNull('asking_price')->whereNull('description')
            ->whereDoesntHave('ownMedia')->whereDoesntHave('purchaseCar')->whereDoesntHave('parkVehicle')->whereDoesntHave('positions')
            ->whereNotIn('id', Thread::whereNotNull('offer_id')->select('offer_id'))
            ->whereNotIn('id', Candidate::whereNotNull('offer_id')->select('offer_id'));
    }

    /**
     * В продаже на сайте: открыто, срок приёма не вышел, без активной сделки.
     * Одна дверь витрины — её читают каталог, счётчики и таб «Предложения»:
     * вышел срок или принято подтверждение — с сайта ушло, в CRM осталось.
     */
    public function scopeOnSale(Builder $q): Builder
    {
        return $q->where('state', OfferState::Open)
            ->where(fn ($w) => $w->whereNull('bids_close_at')->orWhere('bids_close_at', '>', now()))
            ->whereDoesntHave('deal');
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
        // Ролей может быть несколько (менеджер и модератор): видно то, что видно хоть одной из них.
        $any = false;
        $q->where(function ($w) use ($user, &$any) {
            if ($user->isModerator()) {
                $any = true;
                $w->orWhere(fn ($m) => $m->whereIn('moderator_id', $user->teamIds())->whereDoesntHave('purchaseCar'));
            }
            if ($user->hasRole(Role::Manager)) {
                $any = true;
                $w->orWhere(fn ($m) => $m->whereIn('state', [OfferState::Open, OfferState::Gallery])
                    ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->id)->where('opens_at', '<=', now())));
            }
            // Проверяющий: открытые, которые всем сразу и без исключений; без показов и менеджера.
            if ($user->hasRole(Role::Reviewer)) {
                $any = true;
                $w->orWhere(fn ($m) => $m->where('state', OfferState::Open)->where(fn ($r) => $r->whereNull('audience_rules')
                    ->orWhereRaw("audience_rules = '[{\"id\": null, \"type\": \"rest\", \"delay\": 0}]'::jsonb")));
            }
            if ($user->hasRole(Role::Buyer)) {
                $any = true;
                if (! $user->manager_id) {
                    $w->orWhereRaw('false');
                } else {
                    $groups = $user->groupIds();
                    $w->orWhere(fn ($m) => $m->where('state', OfferState::Open)
                        ->whereHas('showings', fn ($s) => $s->where('manager_id', $user->manager_id)
                            ->where(fn ($g) => $g->where('user_id', $user->id)->when($groups, fn ($g) => $g->orWhereIn('group_id', $groups))))
                        ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->manager_id)->where('opens_at', '<=', now())));
                }
            }
        });

        // Ни одной роли с предложениями (посетитель, «Парковка») — только галерея.
        return $any ? $q : $q->where('state', OfferState::Gallery);
    }

    /**
     * Предложения в CRM: админу — все, модератору — его группы не из закупки. Не то же, что `visibleTo`: модератор, который
     * ещё и менеджер, на сайте видит открытое своего круга, но в CRM правит только черновики группы.
     */
    public function scopeInCrm(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }
        if ($user->isModerator()) {
            return $q->whereIn('moderator_id', $user->teamIds())->whereDoesntHave('purchaseCar');
        }

        return $q->whereRaw('false');
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
        return $user->isAdmin() || ($user->isModerator() && self::query()->whereKey($this->id)->inCrm($user)->exists());
    }

    /**
     * Удалить можно только черновик, который ни разу не выходил наружу: без подтверждений, сделки и закупки. Модератор —
     * только заведённый им самим (владелец 04.10.2026: «свои можно, чужие нет»), админ — любой. ТС парковки, стоявшая
     * раньше черновика, — не его: такое снимают «Снять с продажи».
     */
    public function isDeletableBy(User $user): bool
    {
        if ($this->state !== OfferState::Draft || $this->published_at !== null || ! ($user->isAdmin() || ($user->isModerator() && $this->moderator_id === $user->id))) {
            return false;
        }

        return ! $this->bids()->exists() && ! $this->deal()->exists() && ! $this->purchaseCar()->exists()
            && ! $this->parkVehicle()->where('created_at', '<', $this->created_at)->exists();
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
     * Тип ТС — иконка перед названием и фильтр «Тип ТС»: хранится колонкой `vehicle_category` (её считает `saving`),
     * так что иконка и фильтр не расходятся.
     */
    public function category(): Category
    {
        return Category::tryFrom((string) $this->vehicle_category) ?? $this->guessCategory();
    }

    /**
     * Тип ТС заново: по кузову, иначе как у этой ТС на парковке, иначе по марке и модели («Газель», «КАМАЗ»); не
     * понять — легковой.
     */
    public function guessCategory(): Category
    {
        $park = $this->relationLoaded('parkVehicle') ? $this->parkVehicle?->category
            : ($this->exists ? Vehicle::where('offer_id', $this->id)->first(['category'])?->category : null);

        return $this->body?->category() ?? $park ?? Category::guess($this->title()) ?? Category::Passenger;
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

    /**
     * VIN целиком этому человеку. Покупателю — только если менеджер открыл ему предложение с VIN (`showings.show_vin`,
     * лично или группе; владелец 04.10.2026: «менеджер должен решать»); остальным — по глазику в редакторе (`show_vin`).
     */
    public function vinOpenTo(?User $user): bool
    {
        if ($user?->isBuyer()) {
            return Showing::where('offer_id', $this->id)->where('show_vin', true)
                ->where(fn ($w) => $w->where('user_id', $user->id)->orWhereIn('group_id', DB::table('buyer_group_user')->where('user_id', $user->id)->select('group_id')))
                ->exists();
        }

        return (bool) $this->show_vin;
    }

    /** VIN, каким его видит человек: целиком или только первые пять знаков. */
    public function vinFor(?User $user): ?string
    {
        if (! $this->vin) {
            return null;
        }

        // Скрытый — только первые пять знаков (производитель), середина и конец звёздочками: по хвосту VIN машину находят.
        return $this->vinOpenTo($user) ? $this->vin : substr($this->vin, 0, 5).str_repeat('*', max(0, strlen($this->vin) - 5));
    }

    // -------------------------------------------------------------- деньги

    /**
     * Заявленная цена — та, что менеджер считает закупочной. По умолчанию равна
     * закупочной; задана отдельно — менеджер видит её, настоящая остаётся у нас.
     */
    /**
     * Чат по предложению с площадкой: пока оно на витрине или в галерее, а тому, кто с машиной работает (`worksWith`:
     * сделка, гараж, вывоз), — на всех этапах до продажи из гаража и после (05.10.2026, владелец: «пока тачка в гараже у
     * менеджера, писать ему, задавать вопросы»). Галка «Чат с покупателями» таких не закрывает.
     */
    public function chatOpenFor(?User $user): bool
    {
        return $user && $user->canChat()
            && (($this->chat_enabled && ($this->state->isPublic() || $this->state->acceptsInterest())) || $this->worksWith($user));
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

    /** В слоте: черновик или галерея, которые часы выпустят в `slot_at` (`Offers\Slots`, `PublishDueSlots`). */
    public function isScheduled(): bool
    {
        return $this->slot_at !== null && in_array($this->state, [OfferState::Draft, OfferState::Gallery], true);
    }

    public function scopeScheduled(Builder $q): Builder
    {
        return $q->whereNotNull('slot_at')->whereIn('state', [OfferState::Draft, OfferState::Gallery]);
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

    /** Поиск в CRM: то же, что `search`, плюс номер убытка (`claim_ref`) — на витрине его нет. */
    public function scopeSearchCrm(Builder $q, string $term): Builder
    {
        return $q->where(fn ($w) => $w->search($term)->orWhereRaw('lower(claim_ref) like ?', ['%'.mb_strtolower(trim($term)).'%']));
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
