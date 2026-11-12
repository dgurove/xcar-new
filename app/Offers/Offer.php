<?php

namespace App\Offers;

use App\Cars\Body;
use App\Cars\Category;
use App\Cars\Events\CarChanged;
use App\Cars\OnCar;
use App\Cars\Region;
use App\Cars\Settlement;
use App\Garage\Car;
use App\Garage\GaragePayer;
use App\Http\Admin\OfferController;
use App\Live\Publisher;
use App\Live\Topics;
use App\Logistics\Choices;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Mail\Candidate;
use App\Mail\Template;
use App\Mail\Thread;
use App\Offers\Partner\Handover;
use App\Offers\Partner\PartnerOffers;
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
use App\Support\Units;
use App\Support\VendorNames;
use App\Users\Role;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\DealFormat;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use App\Workflow\DeadlineSource;
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
    'brand_id', 'no_brand', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'body', 'transmission', 'drive', 'fuel',
    'engine_volume', 'engine_power', 'color', 'damage_cause', 'damage_zones', 'is_runnable', 'has_keys', 'papers',
    'incident_date', 'description', 'settlement_id', 'inspection_address', 'show_address', 'floor_price', 'owner_price', 'value', 'publish_price',
    'asking_price', 'min_bid_price', 'min_bid_share', 'prices_include_vat', 'tags', 'tag_colors', 'bids_close_at', 'sort_weight',
    'chat_enabled', 'share_locked', 'share_locked_by_estimate', 'audience_rules', 'recommended', 'vendor_id', 'claim_ref', 'insurer_deadline_at', 'car_place',
    'answer_by', 'insured_name', 'insured_phone', 'insured_phone2', 'flags', 'holder', 'docs_required', 'contact_name', 'contact_email',
    'garage_allowed', 'deal_show_until', 'deal_published_at', 'deal_audience_rules', 'binding_until', 'held_by', 'plate', 'sts', 'pts', 'body_no', 'chassis_no', 'policy_no', 'vehicle_category', 'first_look_until', 'first_look_user_id', 'floor_rules',
])]
class Offer extends Model
{
    use HidesDemo;
    use OnCar;

    public const DEFAULT_SHARE = 0.6;

    /** Окна «Новые» и «Горящие» в часах (владелец 10.10.2026; новые — сутки с 12.10.2026). */
    public const NEW_HOURS = 24;

    public const HOT_HOURS = 12;

    /**
     * Вендор сам прислал предложение письмом: у предложения ветка почты со входящим (пересылка сотрудника — тоже). Такие
     * не запрашиваем у вендора (`ClaimRequest`) и сами в галерею не отправляем (18.10.2026, начальник: галерея — сбор
     * интереса к машинам с Мигторга, а письмо — уже предложение). Флага нет: письмо отвязали — признак ушёл.
     */
    public const LETTER_SQL = "exists (select 1 from mail_threads t join mail_messages m on m.thread_id = t.id where t.offer_id = offers.id and m.direction = 'in')";

    /** Поля, от которых зависит вкладка CRM или вид строки в ней (`saved` → живое обновление строки). */
    private const ROW_FIELDS = ['state', 'tab_at', 'number', 'floor_price', 'asking_price', 'publish_price', 'bids_close_at', 'slot_at',
        'auction_at', 'auction_planned_at', 'gallery_from', 'gallery_until', 'urgent_at', 'proposed_at', 'vendor_id', 'recommended', 'binding_until'];

    /** Машина (`Cars\Car`) — с каждым предложением: название, VIN, фото. */
    protected $with = ['car'];

    protected static function booted(): void
    {
        // `opened_at` — вычисленное каталогом (`CatalogQuery::openedAtSql`), колонки нет: в запись не уходит.
        static::saving(fn (self $o) => $o->offsetUnset('opened_at'));
        // `viewed` — «Просмотрено» из каталога (`CatalogQuery::cards`), тоже без колонки.
        static::saving(fn (self $o) => $o->offsetUnset('viewed'));
        // `binding_panic` — вычисленное списком (`Binding::select`).
        static::saving(fn (self $o) => $o->offsetUnset('binding_panic'));
        // `any_bids` — есть ли у машины живые подтверждения (`CatalogQuery::cards`, «Сгорает!»), без колонки.
        static::saving(fn (self $o) => $o->offsetUnset('any_bids'));
        // Эксклюзив оценившего (`FirstLook`) кончается вместе с приёмом: продлили или сдвинули приём («+15 мин», «Показ») —
        // сдвинулся и он (ревью 02.11.2026: «+1 ч» Роме не давал ничего — в прежний срок машина открывалась всем).
        static::saving(function (self $o) {
            if ($o->first_look_until && $o->bids_close_at && $o->isDirty('bids_close_at') && ! $o->isDirty('first_look_until')) {
                $o->first_look_until = $o->bids_close_at;
            }
            // Ушло из продажи (черновики, аукцион, сделка, архив) — эксклюзива больше нет, и его волна «только ему» гаснет:
            // опубликуют снова — по правилам вендора, а не тихо одному (ревью 02.11.2026).
            if ($o->first_look_user_id && $o->isDirty('state') && $o->state !== OfferState::Open) {
                if ($o->audience_rules && AudienceRules::normalize($o->audience_rules) === AudienceRules::normalize([['type' => 'user', 'id' => $o->first_look_user_id, 'delay' => 0], ['type' => 'rest', 'delay' => null]])) {
                    $o->audience_rules = null;
                }
                $o->first_look_until = null;
                $o->first_look_user_id = null;
            }
        });
        // Стоит у менеджера без его сделки (`held_by`, 21.10.2026) — пока машина на месте: уехала (забрали, привезли,
        // выдали с парковки) — метка гаснет.
        static::saving(function (self $o) {
            // Место — поле машины (`cars.place`): она пишется раньше дорожки, к этому часу место уже «изменено», а не «грязное».
            if ($o->held_by && ($o->isDirty('car_place') || $o->carRecord()->wasChanged('place')) && ! $o->isDirty('held_by')) {
                $o->held_by = null;
            }
        });
        static::saving(function (self $o) {
            // У продажи свой вендор: «АльфаСтрахование СПб» парковки здесь — «АльфаСтрахование».
            if ($o->isDirty('vendor_id')) {
                $o->vendor_id = Vendor::forCrm($o->vendor_id);
            }
            // Вендор встал черновику — его метки по умолчанию (11.10.2026).
            VendorTags::apply($o);
            // Регион машины (22.10.2026, регионы менеджера): город продажи, иначе названный в адресе осмотра.
            if (! $o->exists || $o->isDirty(['settlement_id', 'inspection_address'])) {
                $o->region_id = Region::of($o->settlement_id, $o->inspection_address);
            }
            // Шаг работы сменился — предложение пришло в новую вкладку CRM: там оно первым (`tab_at`, 15.10.2026).
            $now = $o->getAttributes();
            $was = $o->getRawOriginal();
            if (! $o->exists || self::tabKeyOf($now) !== self::tabKeyOf($was)) {
                $o->tab_at = now();
                // И кто перевёл (18.10.2026): аватар у времени в строке. Письмо, часы, файл — без человека.
                $o->tab_by = auth()->id();
            }
        });
        // Продажа кончилась (в архив, снята) — в истории ТС парковки «Снята с продажи»; машина и её файлы — у машины,
        // парковку это не трогает (владелец 05.10.2026: «удаление предложения отменяет продажу, а парковку не трогает»).
        // Цена поставщика вписана, но не отправлена (`partner_price_at`, 08.11.2026), а наши уже двинули черновик (цена продажи,
        // слот, выставили, архив): у поставщика его больше нет — метка гаснет, и автоматика, что ждала его «Отправить», идёт сама.
        static::saved(function (self $o) {
            if ($o->partner_price_at && $o->wasChanged(['state', 'asking_price', 'slot_at', 'vendor_id'])
                && ! PartnerOffers::listed(PartnerOffers::eligible(self::whereKey($o->id)))->exists()) {
                $held = $o->created_via === 'partner';
                $o->forceFill(['partner_price_at' => null])->saveQuietly();
                DB::afterCommit(fn () => Handover::after($o, $held));
            }
        });
        static::saved(function (self $o) {
            if ($o->wasChanged('state') && in_array($o->state, [OfferState::Archived, OfferState::Cancelled], true)) {
                Sale::end($o);
            }
            if ($o->car_id && $o->wasChanged(['settlement_id', 'inspection_address'])) {
                Transport::syncRegion($o);
                // Где забрать — в получении у менеджера и в задаче вывоза: экраны машины перечитываются, как от правки
                // её полей (19.10.2026: адрес — поле продажи, не машины, и правка одного адреса не доезжала никуда).
                $car = $o->car_id;
                DB::afterCommit(fn () => CarChanged::dispatch($car, ['inspection_address']));
            }
            // Срок приёма перенесли — шаг «Приём подтверждений» (срок — `DeadlineSource::BidsClose`) идёт по новому (07.11.2026:
            // Нефаз 93341 — приём до 20.11, а шаг закрылся «Срок приёма истёк» 07.11 по сроку первой публикации).
            if ($o->wasChanged('bids_close_at') && $o->bids_close_at) {
                Position::where('offer_id', $o->id)->whereHas('stage', fn ($s) => $s->where('deadline_source', DeadlineSource::BidsClose))
                    ->update(['deadline_at' => $o->bids_close_at] + ($o->bids_close_at->isFuture() ? ['reminded_at' => null, 'overdue_at' => null] : []));
            }
            // Тревога в шапке (`Binding::touch`): новый срок, ушло из продажи или вернулось.
            if ($o->wasChanged(['binding_until', 'state'])) {
                Binding::touch($o);
            }
            // Срок обязывающего — в строке «Вывоза» логистики и у всех экранов машины (19.10.2026).
            if ($o->car_id && $o->wasChanged('binding_until')) {
                $car = $o->car_id;
                DB::afterCommit(fn () => CarChanged::dispatch($car, ['binding_until']));
            }
            // Строка могла уехать в другую вкладку CRM (шаг работы, слот, аукцион, срок приёма, окно галереи) или поменять
            // цены — открытые списки у всех перечитывают её (18.10.2026: правка цены, слот, аукцион, продление срока
            // событий не слали, и строка висела во вкладке до перезагрузки). Номер сменился при выходе в продажу — и
            // прежний: на экранах строка ещё с ним. После коммита — `Publisher`.
            if ($o->wasChanged(self::ROW_FIELDS)) {
                $keys = array_filter([$o->number, $o->wasChanged('number') ? $o->getOriginal('number') : null]);
                app(Publisher::class)->rowsSoon(Topics::STAFF, ['/offers' => $keys, '/gallery' => $keys]);
                // Поставщики вендора (partner.xcar.ru, 07.11.2026) — сигнал перечитать свой список, без полей; только вендорам,
                // у которых поставщики есть.
                foreach (array_unique(array_filter([$o->vendor_id, $o->wasChanged('vendor_id') ? $o->getOriginal('vendor_id') : null])) as $vendor) {
                    in_array((int) $vendor, PartnerOffers::vendorIds(), true) && app(Publisher::class)->refreshSoon(Topics::partner((int) $vendor), ['/offers']);
                }
            }
        });
        static::deleted(fn (self $o) => $o->number ? app(Publisher::class)->rowsSoon(Topics::STAFF, ['/offers' => [$o->number], '/gallery' => [$o->number]]) : null);
        // Черновик удаляют (бросили, «Отменить», ночная уборка) — так же, записью в истории ТС; у архивного и снятого она
        // уже есть.
        static::deleting(fn (self $o) => in_array($o->state, [OfferState::Archived, OfferState::Cancelled], true) ? null : Sale::end($o));
    }

    protected function casts(): array
    {
        return [
            'state' => OfferState::class,
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
            'floor_rules' => 'array',
            'partner_price_at' => 'datetime',
            'published_at' => 'datetime',
            'bids_close_at' => 'datetime',
            'first_look_until' => 'datetime',
            'intake_closed_at' => 'datetime',
            'no_bids_alerted_for' => 'datetime',
            'deal_show_until' => 'datetime',
            'deal_published_at' => 'datetime',
            'deal_audience_rules' => 'array',
            'binding_until' => 'datetime',
            'binding_alerted_for' => 'datetime',
            'slot_at' => 'datetime',
            'gallery_from' => 'datetime',
            'gallery_until' => 'datetime',
            'urgent_at' => 'datetime',
            'auction_at' => 'datetime',
            'auction_planned_at' => 'datetime',
            'proposed_at' => 'datetime',
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

    /** Тип ТС у предложения раньше был своей колонкой `vehicle_category` — теперь это тип машины. */
    public static function carAliases(): array
    {
        // Где машина физически — свойство машины (`cars.place`, 29.10.2026), у продажи по-прежнему `car_place`.
        return ['vehicle_category' => 'category', 'car_place' => 'place'];
    }

    protected function carIsDemo(): bool
    {
        return (bool) $this->is_demo;
    }

    /** В продажу идут кадры от страховой и приёма (погрузка и выдача — дело парковки) и все документы. */
    protected function carMediaScope(Builder $query): void
    {
        self::saleMedia($query);
    }

    /** Кадры машины, что относятся к продаже: без кадров приёма у других вендоров и выдачи — тот же отбор, что `media`. */
    public static function saleMedia(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('collection_name', '!=', 'photos')
            ->orWhereNull('custom_properties->stage')
            ->orWhereIn('custom_properties->stage', [PhotoStage::Vendor->value, PhotoStage::Intake->value]));
    }

    /** Кто в эксклюзиве после своей оценки (`FirstLook`): машина открыта только ему до `first_look_until`. */
    public function firstLookUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_look_user_id');
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

        // Позиция знает своё предложение без запроса: её проверки («ждёт переоценка», письма) читают его связи.
        return $positions->firstWhere('track', $track)?->setRelation('offer', $this);
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
        // Переключили на другие реквизиты после письма (`TakeParallel`, 07.11.2026) — письмо «перевыставить счёт».
        $template = $stage?->template_id && $stage->isRequisitesStep() && $this->deal?->reissue_at ? Template::reissue($stage->template)->id : $stage?->template_id;

        return $template ? "/work/mail/new?offer={$this->number}&template={$template}" : null;
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /** Кто перевёл во вкладку шага работы (`tab_at`): вписал закупочную, поставил цену. Сделала система — пусто. */
    public function tabBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tab_by');
    }

    /** Подтверждение знает своё предложение без запроса (`chaperone`): строка спрашивает закупочную и цену предложения. */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class)->latest()->chaperone('offer');
    }

    public function activeBids(): HasMany
    {
        // Гаражные (без суммы) — после ценовых; равные суммы — кто раньше подтвердил (`id`).
        return $this->hasMany(Bid::class)->where('state', BidState::Active)->orderByRaw('amount desc nulls last')->orderBy('id');
    }

    /**
     * Может ли человек забрать машину себе в гараж подтверждением: галка у предложения и менеджер с гаражом; супер-покупатель
     * — с гаражом по тумблеру (20.10.2026) и только где поставщику можем платить мы (`garageToBuyer`).
     */
    public function garageAllowedFor(?User $user): bool
    {
        return $this->garage_allowed && $user !== null && ($user->isManager() ? $user->canGarage() : $user->garageBuyer() && $user->canBid() && $this->garageToBuyer());
    }

    /**
     * Покупателю в гараж можно: поставщику у него всегда платим мы (20.10.2026), а для этого нужна гаражная ветка маршрута
     * (у Т-Страхования её нет). Совкомбанк — «взяли под себя» той же веткой.
     */
    public function garageToBuyer(): bool
    {
        return $this->garageBranch();
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
     * Открыто сейчас только этому человеку (11.10.2026, владелец: «Открыты только Вам»): в волнах показа (`offer_viewers`)
     * его волна наступила, чужие — нет. С 07.11.2026 и эксклюзив на время — «Роме на 5 дней, потом остальным» (до того
     * считалось только «никому другому никогда», и такие машины стояли в общем списке под «Не просмотренными»).
     */
    public function scopeOnlyFor(Builder $q, User $user): Builder
    {
        return $q->whereHas('viewers', fn ($v) => self::liveViewer($v)->where('user_id', $user->id))
            ->whereDoesntHave('viewers', fn ($v) => self::liveViewer($v)->where('user_id', '!=', $user->id));
    }

    /** Всё, кроме открытого только ему (`scopeOnlyFor`): основной список, когда «Открыты только Вам» стоит своей пачкой. */
    public function scopeNotOnlyFor(Builder $q, User $user): Builder
    {
        return $q->where(fn ($w) => $w->whereDoesntHave('viewers', fn ($v) => self::liveViewer($v)->where('user_id', $user->id))
            ->orWhereHas('viewers', fn ($v) => self::liveViewer($v)->where('user_id', '!=', $user->id)));
    }

    /**
     * Строка показа, что открывает машину сейчас: волна наступила, а у сделки, опубликованной для других, — волна этой
     * публикации (строки продажи остаются историей показа, `SyncViewers`).
     */
    private static function liveViewer($v)
    {
        return $v->where('opens_at', '<=', now())
            ->where(fn ($w) => $w->whereRaw("offers.state <> 'sold'")->orWhereColumn('offer_viewers.opens_at', '>=', 'offers.deal_published_at'));
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

    /** Запрос номеров вендору, в котором номер ушёл (`Vendors\ClaimRequest`). */
    public function claimRequest(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class);
    }

    public function deal(): HasOne
    {
        // Сделка знает своё предложение без запроса (`chaperone`): гараж и списки звали `$deal->offer` строкой на строку.
        return $this->hasOne(Deal::class)->where('state', DealState::Active)->chaperone('offer');
    }

    /**
     * Сделка, что сейчас идёт, иначе последняя состоявшаяся — дорожка «Сделка» редактора показывает и выданную
     * (деньги, вознаграждение, ДКП остаются видны). Сорванные — только «Историей».
     */
    /** Сделка, которую закрыл отказ поставщика (28.10.2026): архив «отказ поставщика» с причиной. */
    public function declinedDeal(): HasOne
    {
        return $this->hasOne(Deal::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('close_reason', 'supplier'));
    }

    public function lastDeal(): HasOne
    {
        return $this->hasOne(Deal::class)->ofMany(['id' => 'max'], fn ($q) => $q->whereIn('state', [DealState::Active, DealState::Done]));
    }

    /**
     * Сделка, которой машина сейчас «является» (08.11.2026, владелец: «предложение и сделка — два состояния одной сущности;
     * если это сделка — везде должна открываться сделка»): в сделке, гараже или выдана — идущая, иначе последняя состоявшаяся;
     * в продаже, черновике, архиве — нет (сорвавшиеся сделки — только «Историей»). По ней окошко списка предложений
     * открывает сделку (`OfferController::detail`), а ссылки — `crmUrl`.
     */
    public function dealNow(): ?Deal
    {
        return in_array($this->state, [OfferState::Sold, OfferState::Delivered, OfferState::Garage], true) ? $this->lastDeal : null;
    }

    /**
     * Машина в CRM — одна страница, редактор: в сделке он и есть сделка (дорожка «Сделка» видна сразу — на ПК колонкой,
     * прокрутка к ней срезала бы шапку); в гараже — к «Гаражу». По состоянию, без запроса: строки списков зовут на каждую.
     */
    public function crmPath(): string
    {
        return '/offers/'.$this->number.($this->state === OfferState::Garage ? '#garage' : '');
    }

    /** То же адресом с хостом CRM — для парковки, логистики, сайта, писем и Telegram. */
    public function crmUrl(): string
    {
        return Surface::Crm->url($this->crmPath());
    }

    /** Где машина — словом для ссылки: «Сделка», «Гараж» или вкладка предложения. */
    public function crmPlace(?User $user = null): string
    {
        return match ($this->state) {
            OfferState::Garage => 'Гараж',
            OfferState::Sold, OfferState::Delivered => 'Сделка',
            default => $this->crmTab($user),
        };
    }

    /** Переоценки закупочной (`Reprice`): ждущая — плашка «Переоценка» в окошке и редакторе. */
    public function reprices(): HasMany
    {
        return $this->hasMany(Reprice::class);
    }

    public function pendingReprice(): HasOne
    {
        return $this->hasOne(Reprice::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('state', Reprice::PENDING));
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

    /** Вывоз машины в логистике (17.10.2026): последний не отменённый — открытый или привезённый. Связь — подгружается списком. */
    public function pickupTask(): HasOne
    {
        return $this->hasOne(Transport::class, 'car_id', 'car_id')
            ->ofMany(['id' => 'max'], fn ($q) => $q->where('origin', 'sale')->where('kind', TransportKind::Pickup)->where('state', '!=', TransportState::Cancelled));
    }

    /**
     * Вывоз — текущий отрезок маршрута (20.10.2026, `Logistics\Route`): после «Привёз» на стоянку логиста дальше везёт
     * следующий отрезок, а первый уже закрыт. Связь `pickupTask` — первый отрезок (вывоз от владельца).
     */
    public function pickup(): ?Transport
    {
        $head = $this->pickupTask;

        return $head && ! $head->current ? Route::current($head) : $head;
    }

    /**
     * У кого ТС стоит «у менеджера» (`CarPlace::Keeper`): машина в гараже — у её менеджера (05.10.2026, Бородин: везёт
     * наш Алексей, а стоять ей у Бородина), иначе — у менеджера идущей сделки.
     */
    public function keeper(): ?User
    {
        return Choices::managerOf($this);
    }

    /**
     * Менеджер, у которого машина стоит, хотя его сделки уже нет (21.10.2026, Иван Заикин: взял в гараж, привёз, чинить не
     * стал — продают другому, а забирать у него). Ставит «Вернуть в продажу» (`ReturnToSale`), гасит уезд машины.
     */
    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** У кого машина сейчас стоит «у менеджера»: у оставившего её (`held_by`), иначе у держателя гаража или покупателя. */
    public function standsWith(): ?User
    {
        return $this->held_by ? $this->heldBy : $this->keeper();
    }

    /** ТС уже забрали у владельца: кто вывозит — менять поздно. */
    public function pickedUp(): bool
    {
        // Стоит у менеджера без сделки — для новой сделки он и есть «владелец»: вывоз от него ещё впереди.
        if ($this->held_by && $this->car_place === CarPlace::Keeper) {
            return (bool) Transport::openFor($this)?->taken();
        }

        return in_array($this->car_place, [CarPlace::Moving, CarPlace::Keeper, CarPlace::WithUs, CarPlace::Ours, CarPlace::Elsewhere, CarPlace::Depot, CarPlace::Buyer], true)
            || (bool) $this->pickup()?->taken();
    }

    /** Можно ли при принятии подтверждения поручить вывоз менеджеру: ТС ещё у владельца, не у нас на парковке. */
    public function pickupChoosable(): bool
    {
        return ! $this->pickedUp() && $this->parkVehicle?->state !== VehicleState::Stored
            && ! in_array($this->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true);
    }

    /**
     * Работает ли менеджер с этой ТС: держит её в гараже, вывозит или ведёт по ней сделку (идущую или закрытую).
     * Им открываются отмеченные документы (`Media\ForManagers`) и кадры.
     */
    /**
     * Менеджер, что с машиной работает как продавец: держатель гаража или покупатель сделки. Исполнитель вывоза сюда не
     * входит (19.10.2026, владелец: «исполнителям документы — только что открыли»): его файлы — только через
     * `Logistics\FileAccess`. Им же решает `FileController`.
     */
    public function managedBy(User $user): bool
    {
        return Car::where('offer_id', $this->id)->where('manager_id', $user->id)->exists()
            || Deal::where('offer_id', $this->id)->where('buyer_id', $user->id)->whereIn('state', [DealState::Active, DealState::Done])->exists();
    }

    public function worksWith(User $user): bool
    {
        return Transport::where('car_id', $this->car_id)->where('executor_id', $user->id)->where('state', '!=', TransportState::Cancelled)->exists()
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
     * Видит ли человек сделку как предложение: машину в сделке опубликовали для других менеджеров (`PublishDeal`) и его волна
     * наступила (`offer_viewers`, те же правила «кто → когда», что у открытого). Участники сделки видят её своими дверями.
     */
    public function seesDeal(?User $user): bool
    {
        if (! $user || ! $user->hasRole(Role::Manager) || ! $this->showsDealAsOpen()) {
            return false;
        }
        // Строки продажи (до публикации) — история показа, не дверь: видит тот, чья волна публикации наступила.
        return $this->viewers()->where('user_id', $user->id)->where('opens_at', '<=', now())->where('opens_at', '>=', $this->deal_published_at)->exists();
    }

    /**
     * Машину в сделке (и гаражную, пока не привезли) опубликовали для других менеджеров и срок не вышел (`PublishDeal`): им
     * это обычное предложение (05.11.2026, владелец: «визуально нигде ничем не отличается от других»), срок приёма — конец
     * публикации (`closesAt`), подтверждение встаёт резервом.
     */
    public function showsDealAsOpen(): bool
    {
        return $this->state === OfferState::Sold && $this->deal_published_at !== null
            && ($this->deal_show_until === null || $this->deal_show_until->isFuture());
    }

    /**
     * До когда идёт приём для витрины. Опубликованную сделку менеджер видит обычным предложением: до конца публикации; иначе
     * (резерв того, кого обошли до срока) — срок приёма. Близнец запросом — `closesAtSql`.
     */
    public function closesAt(): ?Carbon
    {
        if ($this->state !== OfferState::Sold) {
            return $this->bids_close_at;
        }

        return $this->deal_published_at ? $this->deal_show_until : $this->bids_close_at;
    }

    /** `closesAt` запросом: пачки и сортировки каталога. */
    public static function closesAtSql(): string
    {
        return "(case when offers.state = 'sold' and offers.deal_published_at is not null then offers.deal_show_until else offers.bids_close_at end)";
    }

    /** Приём для этого человека идёт: открыт всем или машину в сделке показывают ему. */
    public function openFor(?User $user): bool
    {
        return $this->bidsOpen() || $this->canBidInDeal($user);
    }

    /** Может подтвердить в сделке резервом: сделку опубликовали для других и ему она открыта. */
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
     * Всё, кроме пустых черновиков, — списки, счётчики и поиск CRM. Подзапросом номеров, а не `whereNot(emptyDraft)`: из
     * отрицания Postgres делал «или» и на каждом подсчёте перебирал все файлы машин (`media`, 63 тыс. строк) — 80 мс на
     * подсчёт, а их у «Все» CRM восемь (29.10.2026); подзапрос сначала отбирает горстку пустых черновиков — 1 мс.
     */
    public function scopeNotEmptyDraft(Builder $q): Builder
    {
        return $q->whereNotIn('offers.id', self::query()->emptyDraft()->select('offers.id'));
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
        // Поставщику (07.11.2026) — ни витрины, ни галереи: его черновики идут только через `Partner\PartnerOffers`.
        if (! $user || $user->isSupplier()) {
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
            // Сделка, опубликованная для других менеджеров (`PublishDeal`): видна в предложениях обычным предложением.
            if ($user->hasRole(Role::Manager)) {
                $w->orWhere(fn ($m) => $m->dealOpenTo($user));
            }
            // Проверяющий: открытые, которые всем сразу и без исключений; без показов и менеджера.
            if ($user->hasRole(Role::Reviewer)) {
                $any = true;
                // Свои пусты — значит, как у вендора: «всем сразу», только если и у вендора волн нет.
                $w->orWhere(fn ($m) => $m->where('state', OfferState::Open)->where(fn ($r) => $r->where(fn ($n) => $n->whereNull('audience_rules')
                    ->where(fn ($v) => $v->whereNull('vendor_id')->orWhereHas('vendor', fn ($x) => $x->whereNull('audience_rules'))))
                    ->orWhereRaw("audience_rules = '[{\"id\": null, \"type\": \"rest\", \"delay\": 0}]'::jsonb")));
            }
            if ($user->hasRole(Role::Buyer)) {
                $any = true;
                // Без доступа к предложениям (11.11.2026, `User::hasBuyerAccess`) — ничего, как без менеджера.
                if (! $user->manager_id || ! $user->hasBuyerAccess()) {
                    $w->orWhereRaw('false');
                } else {
                    // Всё, что открыто его менеджеру (волна менеджера наступила), — или только открытое менеджером вручную
                    // (`buyer_sees = picked`, 12.10.2026).
                    $groups = $user->userGroupIds();
                    $w->orWhere(fn ($m) => $m->where('state', OfferState::Open)
                        // Срочное (12.10.2026) — всем покупателям менеджера, и «выбранным» тоже.
                        ->when(! $user->buyerSeesAll(), fn ($m) => $m->where(fn ($p) => $p->where(fn ($u) => $u->urgent())
                            ->orWhereHas('showings', fn ($s) => $s->where('manager_id', $user->manager_id)
                                ->where(fn ($g) => $g->where('user_id', $user->id)->when($groups, fn ($g) => $g->orWhereIn('group_id', $groups))))))
                        ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->manager_id)->where('opens_at', '<=', now())));
                }
            }
            // Галерея — всем авторизованным, без волн и показов (владелец 08.10.2026: «чтобы все могли оставлять интерес»);
            // покупателю без менеджера — нет: у него только своя машина в гараже (21.10.2026).
            if (! $user->onlyGarage()) {
                $any = true;
                $w->orWhere(fn ($g) => $g->inGallery());
            }
        });

        return $any ? $q : $q->whereRaw('false');
    }

    /**
     * Сделка, открытая менеджеру: опубликована для других, срок не вышел и его волна наступила. Та же дверь, что `seesDeal`,
     * но запросом.
     */
    public function scopeDealOpenTo(Builder $q, ?User $user): Builder
    {
        if (! $user || ! $user->hasRole(Role::Manager)) {
            return $q->whereRaw('false');
        }

        return $q->where('state', OfferState::Sold)->whereHas('deal')->whereNotNull('deal_published_at')
            ->where(fn ($w) => $w->whereNull('deal_show_until')->orWhere('deal_show_until', '>', now()))
            ->whereHas('viewers', fn ($v) => $v->where('user_id', $user->id)->where('opens_at', '<=', now())
                ->whereColumn('offer_viewers.opens_at', '>=', 'offers.deal_published_at'));
    }

    /**
     * Регионы человека (22.10.2026; с того же дня — фильтр, а не замок): только машины его регионов и машины без региона.
     * Нет регионов — без условия. Сужают каталог, числа таб-бара и уведомления о новых; страница по ссылке открывается.
     */
    /** Машина из его регионов (или без региона, или регионы не выбраны) — тем же правилом, что `scopeInRegionsOf`. */
    public function regionOpenTo(User $user): bool
    {
        $regions = $user->offerRegions();

        return ! $regions || ! $this->region_id || in_array($this->region_id, $regions, true);
    }

    public function scopeInRegionsOf(Builder $q, ?User $user): Builder
    {
        $regions = $user?->offerRegions() ?? [];

        return $q->when($regions, fn ($r) => $r->where(fn ($x) => $x->whereNull('offers.region_id')->orWhereIn('offers.region_id', $regions)));
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
            // Черновик с Мигторга (`MigtorgDrafts`) — всем модераторам: это их работа, которую раньше делали руками (26.10.2026).
            // Черновик поставщика (partner.xcar.ru, 07.11.2026) — всем модераторам: «Без закупочной» — их работа, автор у
            // него — поставщик, а не их группа.
            return $q->where(fn ($w) => $w->whereIn('moderator_id', $user->teamIds())
                ->when($user->canCrmMail(), fn ($m) => $m->orWhere(fn ($n) => $n->autoDrafted()))
                ->orWhere(fn ($n) => $n->migtorgDrafted())
                ->orWhere('offers.created_via', 'partner'))
                ->whereDoesntHave('purchaseCar')
                ->whereNotExists(fn ($e) => $e->from('deals')->whereColumn('deals.offer_id', 'offers.id'))
                ->whereNotExists(fn ($e) => $e->from('garage_cars')->whereColumn('garage_cars.offer_id', 'offers.id'));
        }

        return $q->whereRaw('false');
    }

    /** Пришло письмом вендора (`LETTER_SQL`); `false` — без такого письма. */
    public function scopeByLetter(Builder $q, bool $yes = true): Builder
    {
        return $q->whereRaw(($yes ? '' : 'not ').self::LETTER_SQL);
    }

    public function hasLetter(): bool
    {
        return $this->exists && self::whereKey($this->id)->byLetter()->exists();
    }

    /** Заведено почтой само (`AutoOffer`): без автора, в истории «Заведён из письма». */
    public function scopeAutoDrafted(Builder $q): Builder
    {
        return $q->whereNull('offers.moderator_id')->whereExists(fn ($e) => $e->from('car_events')->whereColumn('car_events.offer_id', 'offers.id')
            ->where('car_events.type', OfferEventType::Created)->whereRaw("(car_events.payload->>'mail')::boolean"));
    }

    /** Заведено с Мигторга само (`Offers\Intake\MigtorgDrafts`): без автора, в истории «Заведён с Мигторга». */
    public function scopeMigtorgDrafted(Builder $q): Builder
    {
        return $q->whereNull('offers.moderator_id')->whereExists(fn ($e) => $e->from('car_events')->whereColumn('car_events.offer_id', 'offers.id')
            ->where('car_events.type', OfferEventType::Created)->whereNull('car_events.user_id')->whereRaw("jsonb_exists(car_events.payload, 'migtorg')"));
    }

    /** Черновик у поставщика (partner.xcar.ru): автоматика его не заполняет, пока он не отдал цену (`PartnerOffers::held`). */
    public function heldByPartner(): bool
    {
        return PartnerOffers::held($this);
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

    /** Срок обязывающего у вендора в днях (поля «Цены» и «Показ») — из подгруженного вендора, без запроса в виде. */
    public function vendorBindingDays(): int
    {
        if (! $this->vendor_id) {
            return 0;
        }
        $vendor = $this->relationLoaded('vendor') && $this->vendor?->id === $this->vendor_id ? $this->vendor : $this->vendor()->first();

        return (int) $vendor?->binding_days;
    }

    /**
     * `isVisibleTo` для списка разом — те же три правила тремя запросами на весь список, а не до трёх на строку (11.11.2026:
     * строки интереса и «Стоят у вас» спрашивали каждую машину отдельно). Ответ — id видимых ключами.
     *
     * @param  iterable<self>  $offers
     * @return array<int, true>
     */
    public static function visibleIdsTo(?User $user, iterable $offers): array
    {
        $ids = collect($offers)->pluck('id')->filter()->unique()->values()->all();
        if (! $user || ! $ids) {
            return [];
        }
        if ($user->isAdmin()) {
            return array_fill_keys($ids, true);
        }
        $seen = self::query()->whereIn('offers.id', $ids)->visibleTo($user)->pluck('offers.id')
            ->merge(Bid::whereIn('offer_id', $ids)->where('user_id', $user->id)->whereIn('state', [BidState::Active, BidState::Accepted])->pluck('offer_id'))
            ->merge(Estimate::open()->whereIn('offer_id', $ids)->where('user_id', $user->id)
                ->whereHas('offer', fn ($o) => $o->where('state', OfferState::Draft))->pluck('offer_id'));

        return array_fill_keys($seen->unique()->all(), true);
    }

    /**
     * Шеринг закрыт этому человеку: галкой админа — всем, на время оценки — только тому, кого попросили оценить и кто ещё
     * не ответил (19.10.2026, владелец: «то, что Виталик не оценил, для других висит „Запретить шеринг“ — это только для
     * него»).
     */
    public function shareLockedFor(?User $user): bool
    {
        return $this->share_locked || ($user !== null && $this->share_locked_by_estimate
            && $this->estimates()->where('user_id', $user->id)->where('state', EstimateState::Asked)->exists());
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
     * только заведённый им самим (владелец 04.10.2026: «свои можно, чужие нет»), заведённый почтой, если у него почта
     * (раньше его заводил он сам), и заведённый с Мигторга, админ — любой. ТС парковки, стоявшая раньше черновика, — не его: такое снимают «Снять
     * с продажи».
     */
    public function isDeletableBy(User $user): bool
    {
        $mine = $this->moderator_id === $user->id || ($user->canCrmMail() && $this->isAutoDraft())
            || ($this->moderator_id === null && once(fn () => self::query()->whereKey($this->id)->migtorgDrafted()->exists()));
        // Поставщик (partner.xcar.ru) — только свой: заведённый им самим (07.11.2026).
        $supplier = $user->isSupplier() && $this->moderator_id === $user->id;
        if ($this->state !== OfferState::Draft || $this->published_at !== null || ! ($user->isAdmin() || ($user->isModerator() && $mine) || $supplier)) {
            return false;
        }

        return ! $this->bids()->exists() && ! $this->deal()->exists() && ! $this->purchaseCar()->exists()
            && ! $this->parkVehicle()->where('created_at', '<', $this->created_at)->exists();
    }

    // ------------------------------------------------------------ подписи

    public function title(): string
    {
        return $this->carRecord()->title() ?: 'ТС';
    }

    public function titleWithYear(): string
    {
        return $this->carRecord()->withYear($this->title());
    }

    /** Тип ТС — иконка перед названием и фильтр «Тип ТС»: тип машины (`Car` считает его сам по кузову и марке). */
    public function category(): Category
    {
        return $this->vehicle_category ?? Category::guess($this->title()) ?? Category::Passenger;
    }

    /**
     * Грузовое — не легковой, не мото и не пикап (02.11.2026): в шторке «Отдать на оценку» сразу отмечен свой менеджер
     * (`xcar.heavy_estimator`) и эксклюзив 5 дней. Пикап — лёгкий коммерческий для стоянки, но оценивают его все.
     */
    public function isHeavy(): bool
    {
        return $this->category()->isHeavy() && $this->body !== Body::Pickup;
    }

    /** Строка фактов под названием: год, пробег, коробка, привод. */
    public function facts(): array
    {
        return array_values(array_filter([
            $this->year ? $this->year.' г.' : null,
            Units::km($this->mileage),
            $this->transmission?->label(),
            $this->drive?->label(),
            $this->fuel?->label(),
            Units::liters($this->engine_volume),
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
        $text = trim((string) VendorNames::strip(InternalRefs::hide($text, [$this->claim_ref])));

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
        // Пустая — от закупочной сейчас: правили закупочную — новые подтверждения с новой, у прежних своя (`bids.cost`).
        return $this->publish_price ?? self::declaredFrom($this->floor_price);
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

    public static function declaredFrom(?int $floor): ?int
    {
        return $floor ? Pricing::ceilThousand($floor) : null;
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

    /** Ниже этого не принимаем совсем (26.10.2026): закупочная + `HARD_GAP`, но не выше `minBid` — что принималось, принимается. */
    public const HARD_GAP = 20_000;

    public function hardMinBid(): ?int
    {
        $floor = $this->floor_price;
        if (! $floor) {
            return null;
        }
        $min = $this->minBid();

        return $min ? min($min, $floor + self::HARD_GAP) : $floor + self::HARD_GAP;
    }

    /**
     * Зона цены подтверждения (26.10.2026, владелец): `ok` — не ниже порога, `low` — ниже порога, но можно, с согласованием
     * у поставщика (менеджеру — «Вы уверены?», админу — жёлтым), `bad` — недопустима. Пороги наружу не выдаём.
     */
    /** Во сколько раз выше цены продажи подтверждение — уже «проверьте сумму» (`bidZone` — `high`). */
    public const HIGH_FACTOR = 2;

    public function bidZone(int $amount): string
    {
        $hard = $this->hardMinBid();
        $min = $this->minBid();

        // Ниже закупочной + 20 000 — всегда хотя бы «Вы уверены?», даже по цене продажи (02.11.2026, владелец: 2610142042 —
        // цену продажи по ошибке вписали ниже закупочной, `hardMinBid` сполз к ней, и подтверждения по ней шли как обычные).
        return match (true) {
            // Вдвое и больше выше цены продажи — почти всегда лишний ноль (02.11.2026: 9 400 000 при цене 990 000 и
            // 5 110 000 при 600 000 висели живыми): «Проверьте сумму» менеджеру, красным админу.
            // Цену продажи по ошибке вписали ниже закупочной — тогда мерило закупочная (`PriceGuardTest`).
            $this->asking_price && $amount >= self::HIGH_FACTOR * max($this->asking_price, (int) $this->floor_price) => 'high',
            $hard && $amount < $hard => 'bad',
            $min && $amount < $min => 'low',
            $this->floor_price && $amount < $this->floor_price + self::HARD_GAP => 'low',
            default => 'ok',
        };
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
        $out = [];
        foreach ($percents as $p) {
            $off = (int) round($spread * $p / 100, -2);
            // Только сумма без «Вы уверены?» (`bidZone`): кнопка не предлагает цену, что сама уйдёт в согласование.
            if ($off > 0 && $this->bidZone($asking - $off) === 'ok') {
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
     * Есть ли у предложения живое подтверждение — для строк списков (`crmTab`): все такие предложения одним запросом на
     * веб-запрос (159 запросов на «Приём закрыт», аудит 20.10.2026); в очереди и консоли — прямой запрос.
     */
    private static function hasActiveBids(int $id): bool
    {
        if (app()->runningInConsole()) {
            return Bid::where('offer_id', $id)->where('state', BidState::Active)->exists();
        }

        return isset(once(fn () => Bid::where('state', BidState::Active)->distinct()->pluck('offer_id')->flip()->all())[$id]);
    }

    /**
     * Отдано менеджерам на оценку — открытая просьба (`asked` | `given`): вкладка «На оценке у менеджеров» (`crmTab`). В
     * строках списков — одним запросом на веб-запрос, как `hasActiveBids`.
     */
    public function onEstimate(): bool
    {
        if ($this->relationLoaded('openEstimates')) {
            return $this->openEstimates->isNotEmpty();
        }
        if (app()->runningInConsole()) {
            return $this->estimates()->open()->exists();
        }

        return self::onEstimateIds()[$this->id] ?? false;
    }

    /**
     * Ушло на оценку или вернулось с неё (01.11.2026): вкладка сменилась, хотя поля предложения те же, — `tab_at` и кто
     * перевёл ставятся руками, как у `saving` по `tabKeyOf`. Корректировка цены вкладку не меняет — у неё цена есть.
     */
    public function movedByEstimate(?User $by): void
    {
        if ($this->state === OfferState::Draft && ! $this->asking_price) {
            $this->forceFill(['tab_at' => now(), 'tab_by' => $by?->id])->save();
        }
    }

    /** `once` в статическом методе — один кэш на запрос, а не на каждый объект. */
    private static function onEstimateIds(): array
    {
        return once(fn () => array_fill_keys(Estimate::open()->distinct()->pluck('offer_id')->all(), true));
    }

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
                $this->state === OfferState::Draft && LegacyClaim::has($this->claim_ref_key) => $presets['legacy'],
                $this->slot_at !== null => $presets['slots'],
                $this->state === OfferState::Gallery => 'Галерея',
                $this->intake_closed_at !== null && $user?->canManageCrm() => $presets['done'],
                $this->asking_price > 0 => $presets['priced'],
                $user?->canManageCrm() && $this->onEstimate() => $presets['estimating'],
                $this->floor_price > 0 => $presets['unpriced'],
                default => $presets['nofloor'],
            },
            OfferState::Open => $user?->canManageCrm() && ($this->relationLoaded('bids') ? $this->bids->contains('state', BidState::Active) : self::hasActiveBids($this->id))
                ? $presets['bids'] : $presets['published'],
            OfferState::Sold, OfferState::Delivered => 'Сделки',
            OfferState::Garage => 'Гараж',
            // Отказ поставщика (28.10.2026, владелец: «архив с пометкой»): в архиве, но словом причины, а не «Архив».
            OfferState::Cancelled => $this->declinedDeal ? $presets['archive'].', отказ поставщика' : $presets['archive'],
            default => $presets['archive'],
        };
    }

    /** Черновик предложила ТС из «Заявок на приём» (`Park\Actions\AutoSale`), админ ещё не решил: плашка `x-offer.proposal`. */
    public function proposed(): bool
    {
        return $this->proposed_at !== null && $this->state === OfferState::Draft;
    }

    /** ТС этого предложения ещё ждём на парковке (заявка на приём): метка «Ждём приём». */
    /** ТС этой машины на нашей парковке — ждёт приёма или стоит (группа «На парковке» в «Без продажной цены»). */
    public function onPark(): bool
    {
        return in_array($this->parkVehicle?->state, [VehicleState::Expected, VehicleState::Stored], true);
    }

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
        } elseif (isset($actions[OfferState::Gallery->value]) && Exclusive::due($this)->isNotEmpty()) {
            // Эксклюзив за оценку (`Exclusive`, 09.11.2026): галерею видят все менеджеры — пункта нет.
            unset($actions[OfferState::Gallery->value]);
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

    /**
     * «Сгорает!» (22.10.2026, владелец): горящее, и подтверждений нет ни у кого — кнопка плитки рыжая. Каталог даёт
     * `any_bids` одним подзапросом, иначе — запрос.
     */
    public function burningUnbid(): bool
    {
        if (! $this->isEndingSoon()) {
            return false;
        }
        $any = $this->getAttributes()['any_bids'] ?? $this->bids()->whereIn('state', [BidState::Active, BidState::Accepted])->exists();

        return ! $any;
    }

    /** Горящее: до закрытия приёма меньше `HOT_HOURS` (в SQL — `CatalogQuery::ending`). */
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
            // Срок обязывающего горит, покупателя нет (19.10.2026) — важнее всего, красной полосой.
            Binding::panics($this) => 'binding',
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
        // Сделка — тому, кому её показывают (иначе в каталоге её нет): срок по `closesAt`.
        $at = $this->state->acceptsBids() || $this->state === OfferState::Sold ? $this->closesAt() : null;

        return $at ? (int) max(0, now()->diffInSeconds($at, false)) : null;
    }

    public function isFavoriteOf(?User $user): bool
    {
        return $user && $this->favorites->contains('user_id', $user->id);
    }

    public function log(OfferEventType $type, ?User $user = null, array $payload = []): OfferEvent
    {
        return $this->events()->create(['car_id' => $this->car_id, 'type' => $type, 'user_id' => $user?->id, 'payload' => $payload]);
    }
}
