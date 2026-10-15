<?php

namespace App\Offers;

use App\Cars\Category;
use App\Cars\OnCar;
use App\Cars\Settlement;
use App\Garage\Car;
use App\Garage\GaragePayer;
use App\Http\Admin\OfferController;
use App\Mail\Candidate;
use App\Mail\Thread;
use App\Park\PhotoStage;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Purchases\Car as PurchaseCar;
use App\Search\CarWords;
use App\Search\Term;
use App\Support\Demo\HidesDemo;
use App\Support\InternalRefs;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\DealFormat;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'brand_id', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'body', 'transmission', 'drive', 'fuel',
    'engine_volume', 'engine_power', 'color', 'damage_cause', 'damage_zones', 'is_runnable', 'has_keys', 'papers',
    'incident_date', 'description', 'settlement_id', 'inspection_address', 'show_address', 'floor_price', 'owner_price', 'value', 'publish_price',
    'asking_price', 'min_bid_price', 'min_bid_share', 'prices_include_vat', 'tags', 'tag_colors', 'bids_close_at', 'sort_weight',
    'chat_enabled', 'share_locked', 'share_locked_by_estimate', 'audience_rules', 'recommended', 'vendor_id', 'claim_ref', 'insurer_deadline_at', 'car_place',
    'answer_by', 'insured_name', 'insured_phone', 'flags', 'holder', 'docs_required', 'contact_name', 'contact_email',
    'garage_allowed', 'evacuator_id', 'evacuation_to', 'deal_show_until',
])]
class Offer extends Model
{
    use HidesDemo;
    use OnCar;

    public const DEFAULT_SHARE = 0.6;

    /** Окна «Новые» и «Горящие» в часах (владелец 10.10.2026; новые — сутки с 12.10.2026). */
    public const NEW_HOURS = 24;

    public const HOT_HOURS = 12;

    /** Машина (`Cars\Car`) — с каждым предложением: название, VIN, фото. */
    protected $with = ['car'];

    protected static function booted(): void
    {
        // `opened_at` — вычисленное каталогом (`CatalogQuery::openedAtSql`), колонки нет: в запись не уходит.
        static::saving(fn (self $o) => $o->offsetUnset('opened_at'));
        // `viewed` — «Просмотрено» из каталога (`CatalogQuery::cards`), тоже без колонки.
        static::saving(fn (self $o) => $o->offsetUnset('viewed'));
        static::saving(function (self $o) {
            // У продажи свой вендор: «АльфаСтрахование СПб» парковки здесь — «АльфаСтрахование».
            if ($o->isDirty('vendor_id')) {
                $o->vendor_id = Vendor::forCrm($o->vendor_id);
            }
            // Вендор встал черновику — его метки по умолчанию (11.10.2026).
            VendorTags::apply($o);
            // Шаг работы сменился — предложение пришло в новую вкладку CRM: там оно первым (`tab_at`, 15.10.2026).
            $now = $o->getAttributes();
            $was = $o->getRawOriginal();
            if (! $o->exists || self::tabKeyOf($now) !== self::tabKeyOf($was)) {
                $o->tab_at = now();
            }
        });
        // Продажа кончилась (в архив, снята) — в истории ТС парковки «Снята с продажи»; машина и её файлы — у машины,
        // парковку это не трогает (владелец 05.10.2026: «удаление предложения отменяет продажу, а парковку не трогает»).
        static::saved(function (self $o) {
            if ($o->wasChanged('state') && in_array($o->state, [OfferState::Archived, OfferState::Cancelled], true)) {
                Sale::end($o);
            }
        });
        // Черновик удаляют (бросили, «Отменить», ночная уборка) — так же, записью в истории ТС; у архивного и снятого она
        // уже есть.
        static::deleting(fn (self $o) => in_array($o->state, [OfferState::Archived, OfferState::Cancelled], true) ? null : Sale::end($o));
    }

    protected function casts(): array
    {
        return [
            'state' => OfferState::class,
            'migtorg_rejected' => 'array',
            'car_place' => CarPlace::class,
            'insurer_deadline_at' => 'date',
            'answer_by' => 'datetime',
            'tab_at' => 'datetime',
            'tags' => 'array',
            'tag_colors' => 'array',
            'show_vin' => 'bool',
            'show_address' => 'bool',
            'prices_include_vat' => 'bool',
            'chat_enabled' => 'bool',
            'share_locked' => 'bool',
            'share_locked_by_estimate' => 'bool',
            'recommended' => 'bool',
            'garage_allowed' => 'bool',
            'audience_rules' => 'array',
            'published_at' => 'datetime',
            'bids_close_at' => 'datetime',
            'deal_show_until' => 'datetime',
            'slot_at' => 'datetime',
            'gallery_from' => 'datetime',
            'gallery_until' => 'datetime',
            'urgent_at' => 'datetime',
            'auction_at' => 'datetime',
            'auction_planned_at' => 'datetime',
            'proposed_at' => 'datetime',
            'floor_price' => 'int',
            'shown_floor' => 'int',
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

    /** Тип ТС у предложения раньше был своей колонкой `vehicle_category` — теперь это тип машины. */
    public static function carAliases(): array
    {
        return ['vehicle_category' => 'category'];
    }

    protected function carIsDemo(): bool
    {
        return (bool) $this->is_demo;
    }

    /** В продажу идут кадры от страховой и приёма (погрузка и выдача — дело парковки) и все документы. */
    protected function carMediaScope(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('collection_name', '!=', 'photos')
            ->orWhereNull('custom_properties->stage')
            ->orWhereIn('custom_properties->stage', [PhotoStage::Vendor->value, PhotoStage::Intake->value]));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** ТС парковки той же машины. */
    public function parkVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'car_id', 'car_id');
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

    /**
     * Черновик письма вендору, которое просит текущий этап продажи (шаблон этапа: «уведомление о покупке» после
     * принятия подтверждения); null — этап письма не просит. Кнопка «Письмо вендору» на шаге и окно после «Принять».
     */
    public function vendorLetterUrl(?Stage $stage = null): ?string
    {
        $stage ??= $this->stage();

        return $stage?->template_id ? "/work/mail/new?offer={$this->number}&template={$stage->template_id}" : null;
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
     * Кто платит поставщику у гаражной сделки, когда выбирать нечего (тогда шторки «Принять» и «Отдать в гараж» вопроса не
     * задают): у Совкомбанка (договор комиссии, `DealFormat::Commission`) поставщику всегда платит ООО «ПРАЙМ», а менеджер
     * или покупатель — ПРАЙМ по счёту (10.10.2026, владелец), то есть «платит менеджер»; без гаражной ветки маршрута
     * (Т-Страхование) — тоже менеджер. null — выбор есть.
     */
    public function garagePayerFixed(): ?GaragePayer
    {
        return $this->vendor?->deal_format === DealFormat::Commission || ! $this->garageBranch() ? GaragePayer::Manager : null;
    }

    public function garageBranch(): bool
    {
        $workflow = $this->stage()?->workflow ?? $this->vendor?->workflow(Track::Sale);
        if (! $workflow) {
            return true;
        }

        return Outcome::whereIn('stage_id', Stage::where('workflow_id', $workflow->id)->select('id'))->where('branch', Outcome::GARAGE)->exists();
    }

    /** Оценки цены продажи менеджерами (11.10.2026, `Estimate`). */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class)->latest('id');
    }

    /** Открытые просьбы оценить: ждём менеджера или решения админа. Оценённые — первыми, дальше по сроку. */
    public function openEstimates(): HasMany
    {
        return $this->hasMany(Estimate::class)->open()->orderByRaw("case when offer_estimates.state = 'given' then 0 else 1 end")->orderBy('due_at')->orderBy('id');
    }

    /**
     * Отказы от оценки текущего круга (11.10.2026): после последнего «Взять» или снятия, и только последняя просьба
     * человеку — спросили его снова, отказ уходит.
     */
    public function declinedEstimates(): HasMany
    {
        return $this->hasMany(Estimate::class)->where('offer_estimates.state', EstimateState::Declined)
            ->whereRaw("offer_estimates.declined_at > coalesce((select max(x.updated_at) from offer_estimates x where x.offer_id = offer_estimates.offer_id and x.state in ('taken', 'closed')), '-infinity')")
            ->whereRaw('not exists (select 1 from offer_estimates y where y.offer_id = offer_estimates.offer_id and y.user_id = offer_estimates.user_id and y.id > offer_estimates.id)')
            ->latest('declined_at');
    }

    /**
     * «Нужно оценить» менеджера: черновики, которые админ отдал ему на оценку и ещё не решил. Свой круг — не волны:
     * черновик менеджеру иначе не виден (`scopeVisibleTo` не трогаем).
     */
    public function scopeAskedOf(Builder $q, User $user): Builder
    {
        return $q->where('offers.state', OfferState::Draft)
            ->whereHas('estimates', fn ($e) => $e->open()->where('user_id', $user->id));
    }

    /**
     * Открыто только этому человеку (11.10.2026, владелец: «Открыты только Вам»): в волнах показа (`offer_viewers`) он
     * один, никому другому — ни сейчас, ни позже — предложение не откроется.
     */
    public function scopeOnlyFor(Builder $q, User $user): Builder
    {
        return $q->whereHas('viewers', fn ($v) => $v->where('user_id', $user->id))
            ->whereDoesntHave('viewers', fn ($v) => $v->where('user_id', '!=', $user->id));
    }

    /** Всё, кроме открытого только ему (`scopeOnlyFor`): основной список, когда «Открыты только Вам» стоит своей пачкой. */
    public function scopeNotOnlyFor(Builder $q, User $user): Builder
    {
        return $q->where(fn ($w) => $w->whereDoesntHave('viewers', fn ($v) => $v->where('user_id', $user->id))
            ->orWhereHas('viewers', fn ($v) => $v->where('user_id', '!=', $user->id)));
    }

    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class)->latest();
    }

    public function events(): HasMany
    {
        // Записи одной правки — одной секундой (предложение, потом сделка): порядок по номеру.
        return $this->hasMany(OfferEvent::class)->latest()->latest('id');
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class)->where('state', DealState::Active);
    }

    /**
     * Сделка, что сейчас идёт, иначе последняя состоявшаяся — дорожка «Сделка» редактора показывает и выданную
     * (деньги, вознаграждение, ДКП остаются видны). Сорванные — только «Историей».
     */
    public function lastDeal(): HasOne
    {
        return $this->hasOne(Deal::class)->ofMany(['id' => 'max'], fn ($q) => $q->whereIn('state', [DealState::Active, DealState::Done]));
    }

    /**
     * Сделка, чья закупочная — закупочная предложения (`UpdateDealCost`, 14.10.2026): идущая, а у машины в гараже — её
     * сделка, пока менеджер машину не продал (гаражная без маршрута закрывается сразу, а «Закупочная» в гараже — её).
     */
    public function costDeal(): ?Deal
    {
        $deal = $this->relationLoaded('deal') ? $this->deal : $this->deal()->first();
        if ($deal) {
            return $deal;
        }
        $garage = $this->relationLoaded('garageCar') ? $this->garageCar : $this->garageCar()->first();

        return $garage && ! $garage->isSold() ? $garage->deal : null;
    }

    /** Строка гаража: у кого машина «на подготовке» (менеджер продаёт сам). */
    public function garageCar(): HasOne
    {
        return $this->hasOne(Car::class);
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

    /** ТС уже забрали: вывоз дошёл до места (у менеджера, у нас) — кто и куда, менять поздно. */
    public function pickedUp(): bool
    {
        return in_array($this->position(Track::Service)?->stage->car_place, [CarPlace::Keeper, CarPlace::WithUs], true);
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

    /** Куда вывозят: не назначено — менеджер вывозит к себе, иначе к нам. */
    public function pickupDestination(): Destination
    {
        return Destination::tryFrom((string) $this->evacuation_to) ?? ($this->evacuator_id ? Destination::Keeper : Destination::Ours);
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
            || Car::where('offer_id', $this->id)->where('manager_id', $user->id)->exists()
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

    /**
     * Белый список «Кому показывать в сделке»: менеджеры и группы, которым предложение
     * в сделке видно в каталоге с меткой «Идёт сделка» (DealAudience). Пусто — никому.
     */
    public function dealAudience(): HasMany
    {
        return $this->hasMany(OfferDealAudience::class);
    }

    /**
     * Видит ли человек сделку как предложение: идёт сделка и он в белом списке
     * (сам или через группу). Участники сделки видят её своими дверями, не этой.
     */
    public function seesDeal(?User $user): bool
    {
        if (! $user || $this->state !== OfferState::Sold) {
            return false;
        }
        // Страница дёргает это несколько раз (кнопка, плашка, шторка) — связи грузятся раз.
        $this->loadMissing(['deal', 'dealAudience']);
        if (! $this->deal || $this->dealShowOver()) {
            return false;
        }

        return DealAudience::visibleTo($this, $user);
    }

    /** «Сколько показывать» сделку прошло (`deal_show_until`): список остаётся, но его никто не видит. Пусто — пока идёт сделка. */
    public function dealShowOver(): bool
    {
        return $this->deal_show_until !== null && ! $this->deal_show_until->isFuture();
    }

    /** Может подтвердить в сделке резервом: идёт сделка и менеджер в белом списке. */
    public function canBidInDeal(?User $user): bool
    {
        return ($user?->bidsOnSite() ?? false) && $this->seesDeal($user);
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
     * Вывозы (к менеджеру, к нам), что ещё живы: менеджеру — порученные ему, сотруднику — все с менеджером.
     * Продали и выдали, сняли — из списка ушла.
     */
    public function scopePickupsOf(Builder $q, User $user): Builder
    {
        return $q->whereIn('evacuation_to', [Destination::Keeper->value, Destination::Ours->value])
            ->whereNotIn('state', [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived])
            ->whereHas('positions', fn ($p) => $p->where('track', Track::Service))
            // Админу — все поручения менеджерам, остальным (и админу-менеджеру) — свои.
            ->when($user->isAdmin() && ! $user->isManager(), fn ($q) => $q->whereNotNull('evacuator_id'), fn ($q) => $q->where('evacuator_id', $user->id))
            // Вывозчик забирает ТС по своей сделке — это её шаг, а не поручение: живёт на странице сделки (`Handover`).
            ->whereDoesntHave('deal', fn ($d) => $d->whereColumn('deals.buyer_id', 'offers.evacuator_id'))
            // Везёт свою гаражную машину — «Забрал» на её странице в «Гараже», второй строкой не стоит.
            ->whereDoesntHave('garageCar', fn ($g) => $g->whereColumn('garage_cars.manager_id', 'offers.evacuator_id'));
    }

    /** Ход ответственного за вывоз — «забрать»: на текущем этапе вывоза есть его кнопка той ветки, куда везём. Одним запросом. */
    public function scopeAwaitingPickup(Builder $q): Builder
    {
        return $q->whereHas('positions', fn ($p) => $p->where('track', Track::Service)
            ->whereHas('stage.exits', fn ($e) => $e->where('actor', 'keeper')->whereColumn('workflow_exits.branch', 'offers.evacuation_to')))
            // Забирать рано (`Deal::pickupHold`): Каркаде — до согласия поставщика, Альфа — до оплаты. Бейдж «Гаража» не горит.
            ->whereNot(fn ($o) => $o->whereHas('vendor', fn ($v) => $v->where('deal_format', DealFormat::Wholesale))
                ->whereHas('deal', fn ($d) => $d->whereNull('agreed_at')))
            ->whereNot(fn ($o) => $o->whereHas('vendor', fn ($v) => $v->where('pay_before_pickup', true))
                ->whereHas('deal', fn ($d) => $d->whereNull('prepaid_at')));
    }

    public function scopeEmptyDraft(Builder $q): Builder
    {
        return $q->where('state', OfferState::Draft)->whereNull('published_at')
            ->whereCar(fn ($c) => $c->whereNull('brand_id')->whereNull('model_id')->whereNull('vin')->whereNull('claim_ref'))
            ->whereNull('floor_price')->whereNull('asking_price')->whereNull('description')
            ->whereDoesntHave('media')->whereDoesntHave('purchaseCar')->whereDoesntHave('parkVehicle')->whereDoesntHave('positions')
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
     * состоянии, кроме перенесённого из закупки (там цены Carcade и менеджеров); менеджер и супер-покупатель — открытые
     * из своего круга, когда его волна показа наступила; покупатель — открытые, которые ему показал его менеджер (лично или
     * группе) и которые этому менеджеру доступны; галерею (окно черновика) — все авторизованные, без волн и показов
     * (владелец 08.10.2026: сначала «доступ к галерее всем, чтобы все могли оставлять интерес», затем всем
     * авторизованным); гость — ничего.
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
            // Модератор видит черновики группы в CRM; менеджер-модератор на сайте — менеджер, по своим волнам (13.10.2026:
            // иначе видел всё открытое группы раньше волны и в обход «Не показывать»).
            if ($user->isModerator() && (! $user->hasRole(Role::Manager, Role::SuperBuyer) || Surface::inCrm())) {
                $any = true;
                $w->orWhere(fn ($m) => $m->inCrm($user));
            }
            // Менеджер и супер-покупатель — открытое, когда наступила его волна показа (`offer_viewers`; супер-покупатели в
            // волнах — один менеджер, `AudienceRules`).
            if ($user->hasRole(Role::Manager, Role::SuperBuyer)) {
                $any = true;
                $w->orWhere(fn ($m) => $m->where('state', OfferState::Open)
                    ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->id)->where('opens_at', '<=', now())));
            }
            // Сделка, открытая менеджеру белым списком: видна в предложениях с меткой «Идёт сделка».
            if ($user->hasRole(Role::Manager)) {
                $w->orWhere(fn ($m) => $m->dealOpenTo($user));
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
                    // Всё, что открыто его менеджеру (волна менеджера наступила), — или только открытое менеджером вручную
                    // (`buyer_sees = picked`, 12.10.2026).
                    $groups = $user->groupIds();
                    $w->orWhere(fn ($m) => $m->where('state', OfferState::Open)
                        // Срочное (12.10.2026) — всем покупателям менеджера, и «выбранным» тоже.
                        ->when(! $user->buyerSeesAll(), fn ($m) => $m->where(fn ($p) => $p->where(fn ($u) => $u->urgent())
                            ->orWhereHas('showings', fn ($s) => $s->where('manager_id', $user->manager_id)
                                ->where(fn ($g) => $g->where('user_id', $user->id)->when($groups, fn ($g) => $g->orWhereIn('group_id', $groups))))))
                        ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->manager_id)->where('opens_at', '<=', now())));
                }
            }
            // Галерея — всем авторизованным, без волн и показов (владелец 08.10.2026: «чтобы все могли оставлять интерес»).
            $any = true;
            $w->orWhere(fn ($g) => $g->inGallery());
        });

        return $any ? $q : $q->whereRaw('false');
    }

    /**
     * Сделка, открытая менеджеру: идёт сделка и он в белом списке «Кому показывать
     * в сделке» — сам или через группу. Та же дверь, что `seesDeal`, но запросом.
     */
    public function scopeDealOpenTo(Builder $q, ?User $user): Builder
    {
        if (! $user || ! $user->hasRole(Role::Manager)) {
            return $q->whereRaw('false');
        }
        $groups = DB::table('user_group_user')->where('user_id', $user->id)->pluck('group_id')->all();

        return $q->where('state', OfferState::Sold)->whereHas('deal')
            ->where(fn ($w) => $w->whereNull('deal_show_until')->orWhere('deal_show_until', '>', now()))
            ->where(fn ($w) => $w
                ->whereHas('dealAudience', fn ($a) => $a->where('audience_type', DealAudience::USER)->where('audience_id', $user->id))
                ->orWhere(fn ($g) => $g->whereHas('dealAudience', fn ($a) => $a->where('audience_type', DealAudience::GROUP)->whereIn('audience_id', $groups ?: [0]))));
    }

    /**
     * Витрина предложений: в продаже, сделка, открытая этому менеджеру, или чужая сделка, где он ждёт резервом.
     * Одна дверь каталога и числа таба «Предложения» — расходятся они не должны.
     */
    public function scopeSiteList(Builder $q, ?User $user): Builder
    {
        return $q->where(fn ($all) => $all->where(fn ($v) => $v->visibleTo($user)->where(fn ($w) => $w->onSale()->orWhere(fn ($d) => $d->dealOpenTo($user))))
            // Подтвердил, а приняли другого раньше срока приёма (15.10.2026, владелец: резерв не знает о чужой сделке) — машина
            // стоит у него, как у всех, до конца срока, а не пропадает из списка. Его живое подтверждение и так открывает
            // страницу (`isVisibleTo`).
            ->when($user, fn ($r) => $r->orWhere(fn ($b) => $b->where('offers.state', OfferState::Sold)->whereHas('deal')
                ->where('offers.bids_close_at', '>', now())
                ->whereHas('bids', fn ($x) => $x->where('user_id', $user->id)->where('state', BidState::Active)))));
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
            // Черновик, что завела почта сама (`AutoOffer`), — тем, кому открыта почта CRM: раньше его заводили они
            // «Завести». Именно заведённый почтой (`autoDrafted`), а не любой без автора: у удалённого человека автор
            // тоже пуст.
            // Было в сделке или в гараже — модератору закрыто совсем (15.10.2026, владелец: новая закупочная «не должна
            // показываться никому — ни модераторам»): её история, цены, деньги сделки и письма deal@.
            return $q->where(fn ($w) => $w->whereIn('moderator_id', $user->teamIds())
                ->when($user->canCrmMail(), fn ($m) => $m->orWhere(fn ($n) => $n->autoDrafted())))
                ->whereDoesntHave('purchaseCar')
                ->whereNotExists(fn ($e) => $e->from('deals')->whereColumn('deals.offer_id', 'offers.id'))
                ->whereNotExists(fn ($e) => $e->from('garage_cars')->whereColumn('garage_cars.offer_id', 'offers.id'));
        }

        return $q->whereRaw('false');
    }

    /** Заведено почтой само (`AutoOffer`): без автора, в истории «Заведён из письма». */
    public function scopeAutoDrafted(Builder $q): Builder
    {
        return $q->whereNull('offers.moderator_id')->whereExists(fn ($e) => $e->from('offer_events')->whereColumn('offer_events.offer_id', 'offers.id')
            ->where('offer_events.type', OfferEventType::Created)->whereRaw("(offer_events.payload->>'mail')::boolean"));
    }

    /** Черновик, который завела почта и который ещё не выходил наружу: его можно снять «Не заявка», как после «Завести». */
    public function isAutoDraft(): bool
    {
        return $this->state === OfferState::Draft && $this->published_at === null && $this->moderator_id === null
            && once(fn () => self::query()->whereKey($this->id)->autoDrafted()->exists());
    }

    /**
     * Страница предложения: круг видимости, а своё живое подтверждение (ждёт решения, в резерве, принято) открывает её и
     * вне круга — без 404, пока решается. Отозванное, отклонённое и заменённое не открывает ничего (13.10.2026: менеджер
     * отозвал цену, круг сузили — а он видел машину и дальше).
     */
    public function isVisibleTo(?User $user): bool
    {
        return $user?->isAdmin() || self::query()->whereKey($this->id)->visibleTo($user)->exists()
            || ($user && $this->bids()->where('user_id', $user->id)->whereIn('state', [BidState::Active, BidState::Accepted])->exists())
            || $this->isAskedOf($user);
    }

    /** Черновик отдан этому менеджеру на оценку, и просьба открыта — страница ему открыта (11.10.2026). */
    public function isAskedOf(?User $user): bool
    {
        return $user !== null && $this->state === OfferState::Draft && Estimate::openFor($this, $user) !== null;
    }

    /** CRM: что человеку можно открыть и править. Админу — всё, модератору — предложения его группы не из закупки. */
    public function isEditableBy(User $user): bool
    {
        return $user->isAdmin() || ($user->isModerator() && self::query()->whereKey($this->id)->inCrm($user)->exists());
    }

    /**
     * Удалить можно только черновик, который ни разу не выходил наружу: без подтверждений, сделки и закупки. Модератор —
     * только заведённый им самим (владелец 04.10.2026: «свои можно, чужие нет») и заведённый почтой, если у него почта
     * (раньше его заводил он сам), админ — любой. ТС парковки, стоявшая раньше черновика, — не его: такое снимают «Снять
     * с продажи».
     */
    public function isDeletableBy(User $user): bool
    {
        $mine = $this->moderator_id === $user->id || ($user->canCrmMail() && $this->isAutoDraft());
        if ($this->state !== OfferState::Draft || $this->published_at !== null || ! ($user->isAdmin() || ($user->isModerator() && $mine))) {
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

    /** Тип ТС — иконка перед названием и фильтр «Тип ТС»: тип машины (`Car` считает его сам по кузову и марке). */
    public function category(): Category
    {
        return $this->vehicle_category ?? Category::guess($this->title()) ?? Category::Passenger;
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
        return $this->hasOne(PurchaseCar::class, 'car_id', 'car_id')->orderByDesc('id');
    }

    /**
     * VIN целиком этому человеку. Покупателю — только если менеджер открыл ему предложение с VIN (`showings.show_vin`,
     * лично или группе; владелец 04.10.2026: «менеджер должен решать»); остальным — по глазику в редакторе (`show_vin`).
     */
    public function vinOpenTo(?User $user): bool
    {
        if ($user?->isBuyer()) {
            // Скрытый у предложения VIN не открывает покупателю никто: менеджер не отдаёт того, чего не видит сам (13.10.2026).
            if (! $this->show_vin) {
                return false;
            }
            if ($user->buyerSeesAll() && $user->buyer_show_vin) {
                return true;
            }

            return Showing::where('offer_id', $this->id)->where('show_vin', true)
                ->where(fn ($w) => $w->where('user_id', $user->id)->orWhereIn('group_id', DB::table('buyer_group_user')->where('user_id', $user->id)->select('group_id')))
                ->exists();
        }

        return (bool) $this->show_vin;
    }

    /**
     * Описание для xcar: без ссылок (письмо вендора кладёт «Фото: <ссылка на его облако>») и без номеров убытка и ДЛ
     * (`InternalRefs`, 13.10.2026), без названия вендора (`VendorNames`, 15.10.2026). Пусто после чистки — null.
     */
    public function publicDescription(): ?string
    {
        $text = (string) preg_replace(['~^\s*Фото:\s*\S+\s*$~miu', '~https?://\S+~iu'], '', (string) $this->description);
        $text = trim((string) \App\Support\VendorNames::strip(InternalRefs::hide($text, [$this->claim_ref])));

        return $text !== '' ? $text : null;
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
     * менеджера, писать ему, задавать вопросы»). Галка «Чат с покупателями» таких не закрывает. С витрины — только тому,
     * кому предложение видно (13.10.2026: номер любого опубликованного приходит всем в живых событиях каталога, и чат
     * показывал фото и цену до волны).
     */
    public function chatOpenFor(?User $user): bool
    {
        return $user && $user->canChat()
            && ($this->worksWith($user) || ($this->chat_enabled && $this->state->isPublic() && $this->isVisibleTo($user)));
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
        // Пустая — от закупочной, что видят менеджеры (`bidFloor`): правка закупочной после выхода в продажу заявленную
        // не двигает, и вписывать её за админа не нужно (15.10.2026).
        return $this->publish_price ?? self::declaredFrom($this->bidFloor());
    }

    /**
     * Заявленная глазами человека: менеджеру, у которого черновик на оценке без заявленной (админ скрыл в шторке
     * «Отдать на оценку», 11.10.2026), — ничего; остальным — как есть.
     */
    public function declaredFor(?User $user): ?int
    {
        if ($this->state === OfferState::Draft && ($estimate = Estimate::openFor($this, $user)) && ! $estimate->showsDeclared()) {
            return null;
        }

        return $this->declaredPrice();
    }

    /**
     * Закупочная, с которой подтверждают (15.10.2026): после выхода в продажу правили закупочную — прежняя (`shown_floor`),
     * новая — только закупочная поставщика у админа. Её снимает подтверждение (`bids.cost`), «Отдать в гараж» — сделка.
     */
    public function bidFloor(): ?int
    {
        return $this->shown_floor ?? $this->floor_price;
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

    /**
     * Быстрые скидки формы подтверждения: «−2%» и «−5%» — доля **разницы** между заявленной и продажной, а не всей
     * цены (владелец 10.10.2026: заявленная 90 000, продажи 100 000 — «−5%» это −500 ₽, а не −5 000). Скидка — до 100 ₽;
     * кнопки, что уводят ниже порога (`minBid`) или ничего не скидывают, не показываются: сам порог наружу не выдаём.
     * Супер-покупателю — без быстрых скидок: заявленной он не видит, а по двум суммам от разницы её легко досчитать.
     *
     * @return array<int, int> процент => цена с этой скидкой
     */
    public function bidDiscounts(?User $viewer = null, array $percents = [2, 5]): array
    {
        if ($viewer?->isSuperBuyer()) {
            return [];
        }
        $asking = (int) $this->asking_price;
        $spread = $asking - (int) $this->declaredPrice();
        if (! $asking || ! $this->declaredPrice() || $spread <= 0) {
            return [];
        }
        $min = (int) ($this->minBid() ?? 0);
        $out = [];
        foreach ($percents as $p) {
            $off = (int) round($spread * $p / 100, -2);
            if ($off > 0 && $asking - $off >= $min) {
                $out[$p] = $asking - $off;
            }
        }

        return $out;
    }

    /**
     * Где предложение в CRM — названием вкладки «Предложений» (13.10.2026, владелец: «черновик», «приём подтверждений» —
     * непонятно, где это находится): «Без закупочной цены», «Оцененные», «Опубликованные», «Из таблицы Кати»… Повторяет
     * `OfferController::scopeFor` для одного предложения; вне вкладок — «Сделки» и «Гараж» («Работа»). Модератору
     * подтверждения не видны — открытое у него «Опубликованные».
     */
    /**
     * Шаг работы по полям предложения — то, от чего зависит его вкладка CRM (`crmTab`): состояние, у черновика — аукцион,
     * слот, галерея-состояние, продажная, закупочная. Таблица Кати и подтверждения — не поля предложения, их тут нет.
     */
    public static function tabKeyOf(array $a): string
    {
        $state = $a['state'] ?? null;
        $state = $state instanceof OfferState ? $state->value : (string) $state;
        if (! in_array($state, [OfferState::Draft->value, OfferState::Gallery->value], true)) {
            return $state;
        }

        return match (true) {
            ($a['auction_at'] ?? null) !== null && $state === OfferState::Draft->value => 'auction',
            ($a['slot_at'] ?? null) !== null => 'slots',
            $state === OfferState::Gallery->value => 'gallery',
            (int) ($a['asking_price'] ?? 0) > 0 => 'priced',
            (int) ($a['floor_price'] ?? 0) > 0 => 'unpriced',
            default => 'nofloor',
        };
    }

    public function crmTab(?User $user = null): string
    {
        $user ??= auth()->user();
        $presets = OfferController::PRESETS;

        return match ($this->state) {
            OfferState::Draft, OfferState::Gallery => match (true) {
                $this->onAuction() => $presets['auction'],
                $this->state === OfferState::Draft && LegacyClaim::listed($this) !== null => $presets['legacy'],
                $this->slot_at !== null => $presets['slots'],
                $this->state === OfferState::Gallery => 'Галерея',
                $this->asking_price > 0 => $presets['priced'],
                $this->floor_price > 0 => $presets['unpriced'],
                default => $presets['nofloor'],
            },
            OfferState::Open => $user?->canManageCrm() && ($this->relationLoaded('bids') ? $this->bids->contains('state', BidState::Active) : $this->bids()->where('state', BidState::Active)->exists())
                ? $presets['bids'] : $presets['published'],
            OfferState::Sold, OfferState::Delivered => 'Сделки',
            OfferState::Garage => 'Гараж',
            default => $presets['archive'],
        };
    }

    /** Черновик предложила ТС из «Заявок на приём» (`Park\Actions\AutoSale`), админ ещё не решил: плашка `x-offer.proposal`. */
    public function proposed(): bool
    {
        return $this->proposed_at !== null && $this->state === OfferState::Draft;
    }

    /** ТС этого предложения ещё ждём на парковке (заявка на приём): метка «Ждём приём». */
    public function awaitingPark(): bool
    {
        return $this->parkVehicle?->state === VehicleState::Expected;
    }

    /** Во вкладке «На аукцион» (`SendToAuction`). */
    public function onAuction(): bool
    {
        return $this->auction_at !== null && $this->state === OfferState::Draft;
    }

    /** «По истечению срока на аукцион» — ждёт конца приёма; метка «На аукцион» в CRM. */
    public function auctionPlanned(): bool
    {
        return $this->auction_planned_at !== null && $this->state === OfferState::Open;
    }

    /**
     * Пункты «На аукцион» в «Действиях» (админу): [действие, подпись, вопрос перед]. now — отправить сейчас, after — по
     * истечении срока, cancel — отменить план или вернуть в черновики, sale — шторка «Вернуть в продажу» (`x-offer.resale-sheet`,
     * 13.10.2026, владелец: «не хватает возможности вернуть из аукциона в продажу или в черновики»).
     */
    public function auctionActions(): array
    {
        return match (true) {
            $this->onAuction() => [['sale', 'Вернуть в продажу…', null], ['cancel', 'Вернуть в черновики', null]],
            $this->state === OfferState::Open => array_values(array_filter([
                ['now', 'Отправить на аукцион', 'Снять с публикации и отправить на аукцион?'],
                $this->auctionPlanned() ? ['cancel', 'Отменить отправку на аукцион', null]
                    : ($this->bidsOpen() && $this->bids_close_at ? ['after', 'По истечению срока на аукцион', null] : null),
            ])),
            in_array($this->state, [OfferState::Draft, OfferState::Gallery], true) => [['now', 'Отправить на аукцион', null]],
            default => [],
        };
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

    /**
     * Поиск: номер (и прежний), VIN куском, марка, модель, год, город — по словам (10.10.2026: «Kia Rio» не находилось —
     * фраза сравнивалась целиком). Строка целиком — как номер; иначе каждое слово должно найтись хоть в чём-то
     * (`Search\CarWords`). Запрос разбирает `Search\Term` — тем же разбором ищут шапки трёх хостов.
     */
    public function scopeSearch(Builder $q, string|Term $term, bool $crm = false): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        // VIN на витрине: менеджеру — у тех, где он открыт (`show_vin`), покупателю — никак: VIN ему показан маской, а
        // поиск по куску восстанавливал бы его знак за знаком (13.10.2026).
        $viewer = auth()->user();
        $vin = $crm || ($viewer?->hasRole(Role::Manager, Role::SuperBuyer) ?? false) || ($viewer?->isAdmin() ?? false);
        $vinOpen = $crm ? null : fn ($x) => $x->where('offers.show_vin', true);

        return $q->where(fn ($w) => $w
            // Строка целиком — номер: предложения, прежний, VIN; в CRM ещё номер убытка и телефон владельца.
            // Прежний номер — bigint: число длиннее 18 цифр (расчётный счёт в поиске «Оплат») Postgres отвергал ошибкой, и
            // падала вся выдача — такие с прежними номерами не сравниваются.
            ->when($t->digits, fn ($w, $d) => $w->orWhereRaw('cast(offers.number as text) like ?', [Term::like($d)])
                ->when(strlen($d) <= 18, fn ($a) => $a->orWhereIn('offers.id', DB::table('offer_number_aliases')->where('number', $d)->select('offer_id'))))
            ->when($vin && $t->vin, fn ($w) => $w->orWhere(fn ($x) => $x->whereCar('cars.vin', 'like', Term::like($t->vin))->when($vinOpen, $vinOpen)))
            ->when($crm && $t->key, fn ($w) => $w->orWhere(fn ($x) => $x->whereCar('cars.claim_ref_key', 'like', Term::like($t->key))))
            ->when($crm && $t->phone, fn ($w) => $w->orWhere(fn ($x) => $x->whereCar(fn ($c) => $c->whereRaw(Term::digitsSql('cars.insured_phone').' like ?', [Term::like($t->phone)]))))
            // Слова — через И: «kia rio», «рио 2021», «крета москва».
            ->orWhere(fn ($all) => CarWords::apply($all, $t, fn ($one, $word, $like) => $one
                ->orWhereHas('settlement', fn ($c) => $c->search($word, region: false))
                ->orWhereRaw('cast(offers.number as text) like ?', [$like])
                ->when($crm, fn ($o) => $o->orWhere(fn ($x) => $x->whereCar(fn ($c) => $c->whereRaw('lower(cars.claim_ref) like ?', [$like])))), vin: $vin, vinOpen: $vinOpen)));
    }

    /** Поиск в CRM: то же, что `search`, плюс номер убытка (ключом и куском) и телефон владельца — на витрине их нет. */
    public function scopeSearchCrm(Builder $q, string|Term $term): Builder
    {
        return $this->scopeSearch($q, $term, crm: true);
    }

    // -------------------------------------------------------------- метки

    /**
     * В галерее: черновик в окне показа (`gallery_from`…`gallery_until`, владелец 08.10.2026). Не состояние — черновик
     * идёт своим путём; опубликовали раньше срока — из галереи ушло, интерес остался.
     */
    public function isGallery(): bool
    {
        return $this->state === OfferState::Draft && $this->gallery_until?->isFuture();
    }

    /** Принимает интерес: открытое или черновик в окне галереи. */
    public function acceptsInterest(): bool
    {
        return $this->state->acceptsInterest() || $this->isGallery();
    }

    /**
     * Пункты «Действий» по состоянию; «В галерею» у черновика — окно показа: висит — «Убрать из галереи».
     *
     * @return array<string, string>
     */
    public function stateActions(): array
    {
        $actions = $this->state->actions();
        if ($this->isGallery() && isset($actions[OfferState::Gallery->value])) {
            $actions[OfferState::Gallery->value] = 'Убрать из галереи';
        }
        // С аукциона в продажу — шторкой с ценами и показом (`auctionActions`), в галерею — не отсюда.
        if ($this->onAuction()) {
            unset($actions[OfferState::Open->value], $actions[OfferState::Gallery->value]);
        }

        return $actions;
    }

    public function scopeInGallery(Builder $q): Builder
    {
        return $q->where('offers.state', OfferState::Draft)->where('offers.gallery_until', '>', now());
    }

    /** Окно показа было и закончилось (или черновик ушёл дальше) — для «Откликов» в CRM. */
    public function galleryClosed(): bool
    {
        return $this->gallery_from !== null && ! $this->isGallery();
    }

    /** До конца показа меньше суток. */
    public function isGalleryEndingSoon(): bool
    {
        return $this->isGallery() && $this->gallery_until->lt(now()->addDay());
    }

    /** Когда открылось этому человеку: каталог кладёт `opened_at` (`CatalogQuery::openedAtSql`), иначе — публикация. */
    public function openedAt(): ?Carbon
    {
        $at = $this->getAttributes()['opened_at'] ?? null;

        return $at !== null ? Carbon::parse($at) : $this->published_at;
    }

    /** Новое: открылось этому человеку за последние сутки (`NEW_HOURS`). */
    public function isFresh(): bool
    {
        return $this->openedAt()?->gt(now()->subHours(self::NEW_HOURS)) ?? false;
    }

    /** Горящее: до закрытия приёма меньше 12 ч. */
    public function isEndingSoon(): bool
    {
        $left = $this->secondsLeft();

        return $left !== null && $left > 0 && $left < self::HOT_HOURS * 3600;
    }

    /**
     * Срочное (12.10.2026, владелец: «срочно, всем, огоньком»): опубликовано «Срочно» и приём ещё идёт. Закрылся приём —
     * огонёк гаснет сам; `urgent_at` остаётся историей.
     */
    public function isUrgent(): bool
    {
        return $this->urgent_at !== null && $this->state === OfferState::Open && $this->bidsOpen();
    }

    /** Наценка над закупочной для CRM (`Margin`): у черновика, галереи и открытого. */
    public function marginMark(): ?array
    {
        return in_array($this->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true)
            ? Margin::of($this->asking_price, $this->floor_price) : null;
    }

    /** Срочные с идущим приёмом — запросом (лента «Срочно», пилюля, чип CRM). */
    public function scopeUrgent(Builder $q): Builder
    {
        return $q->where('offers.state', OfferState::Open)->whereNotNull('offers.urgent_at')->where('offers.bids_close_at', '>', now());
    }

    /**
     * Метка строки CRM (12.10.2026, владелец: «в CRM тоже помечать голубым и рыжим»): у открытого — горящее важнее
     * нового (опубликовано за сутки); `hot` | `fresh` | null. Продажная не выше закупочной — `loss` красным важнее всего,
     * и у черновика (14.10.2026).
     */
    public function crmTone(): ?string
    {
        return match (true) {
            ($this->marginMark()['tone'] ?? null) === 'loss' => 'loss',
            $this->state !== OfferState::Open => null,
            $this->isUrgent() => 'urgent',
            $this->isEndingSoon() => 'hot',
            (bool) $this->published_at?->gt(now()->subHours(self::NEW_HOURS)) => 'fresh',
            default => null,
        };
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
