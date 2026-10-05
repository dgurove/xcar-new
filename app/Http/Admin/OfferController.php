<?php

namespace App\Http\Admin;

use App\Chats\Chat;
use App\Chats\Message as ChatMessage;
use App\Garage\Actions\SendViaRoute;
use App\Garage\Actions\TakeToGarage;
use App\Garage\Car as GarageCar;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Garage\GarageView;
use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Thread;
use App\Media\Actions\WarmPhotos;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\PurgeOffer;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Actions\UnlistParkOffer;
use App\Offers\Actions\UnscheduleOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\AudienceRules;
use App\Offers\BidState;
use App\Offers\Jobs\DropEmptyDraft;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Offers\Slots;
use App\Offers\Tag;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfferController
{
    /**
     * Вкладки — шаги работы (владелец 04.10.2026): модератор заполнил закупочную — машина ушла в «Без продажной цены»
     * (05.10.2026: «Без цены» разделена — администратору нужен список только своего), админ оценил — в «Оцененные»,
     * отправили пачкой — в «Публикацию» (по слотам), вышла — в «Опубликованные», где выбирают победителя. Черновики из
     * закупок идут со всеми. Модератору те же вкладки: цены продажи, галочек и отправки у него нет.
     */
    public const PRESETS = [
        'nofloor' => 'Без закупочной цены', 'unpriced' => 'Без продажной цены', 'priced' => 'Оцененные', 'slots' => 'Публикация', 'published' => 'Опубликованные', 'archive' => 'Архив',
    ];

    /** Прежние пилюли — в новые вкладки: ссылки из Telegram, закладки, `LegacyAdmin`. */
    private const LEGACY = [
        'draft' => 'unpriced', 'purchase' => 'unpriced', 'slot' => 'slots', 'open' => 'published', 'bids' => 'published', 'recommended' => 'published',
    ];

    /** Вкладки, где идёт работа по шагам: всегда таблица, без сортировки — порядок задаёт сам шаг. */
    private const STEPS = ['nofloor', 'unpriced', 'priced', 'slots', 'published'];

    /** Вкладки, где карточка после дела идёт к следующей строке. */
    private const TODO = ['nofloor', 'unpriced'];

    /** Вкладка по умолчанию — дело человека: модератору закупочная, админу цена продажи. */
    public static function home(User $user): string
    {
        return $user->canManageCrm() ? 'unpriced' : 'nofloor';
    }

    /** Есть ли у человека дело по черновику на его шаге: закупочная — у всех, цена продажи — у админа. */
    public static function todo(Offer $offer, User $user): bool
    {
        return $offer->state === OfferState::Draft && ! $offer->slot_at && ! $offer->asking_price && (! $offer->floor_price || $user->canManageCrm());
    }

    public const SORTS = ['fresh' => 'Сначала новые', 'number' => 'По номеру'];

    public function index(Request $request)
    {
        $legacy = self::LEGACY[(string) $request->query('preset')] ?? null;
        if ($legacy) {
            $query = ['preset' => $legacy] + ($request->query('preset') === 'recommended' ? ['recommended' => '1'] : []) + $request->except(['preset', 'sort']);

            return redirect('/?'.http_build_query($query), 301);
        }
        // ?peek=номер — карточка строки рядом (first — первая строка без цены, адрес получает её номер ниже).
        $detail = Detail::of($request, fn (string $key) => $this->detailOf($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $admin = $request->user()->canManageCrm();
        $preset = array_key_exists((string) $request->query('preset'), self::PRESETS) ? (string) $request->query('preset') : self::home($request->user());
        $step = in_array($preset, self::STEPS, true);
        // «Без цены» — без сортировки и вида, чипы есть (владелец 04.10.2026: «не хватает фильтров»), по умолчанию — без
        // Каркаде. «Рекомендуем» — переключатель у опубликованных.
        $facets = Facets::for($request, 'crm-offers', ...match ($preset) {
            'published' => [...self::facets(), Facet::toggle('recommended', 'Рекомендуем', fn ($o) => $o->where('recommended', true))],
            default => self::facets(),
        })->always();
        ListPrefs::sync($request, 'crm-offers', rememberTable: true, keep: $facets->keys());
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'fresh';
        // Поиск лупой идёт по всему разделу — мимо вкладки и чипов.
        $searching = $facets->searching();
        // Вендор ни разу не выбирали — все, кроме Каркаде (их десятки из закупки, оценивают отдельно), на любой вкладке:
        // фильтр при переключении вкладок не меняется (владелец 04.10.2026). Сняли чип — ListPrefs помнит пустое, и
        // умолчание больше не встаёт. В ссылки вкладок оно не едет: после чипов параметр убирается (ниже).
        $carcadeHidden = ! $searching && ! $request->query->has('vendor') && $this->hideCarcade($request, $preset);

        // Пустой «+ Новый» в списке не стоит: его либо заполнят, либо он удалится, как только из него уйдут.
        $q = Offer::query()->inCrm($request->user())->whereNot(fn ($o) => $o->emptyDraft())->with(['brand', 'model', 'settlement', 'parkVehicle:id,offer_id,category,accepted_at,created_at'])
            ->withCount(['activeBids', 'interests'])->withMax('activeBids as top_bid', 'amount');

        if ($searching) {
            $q->searchCrm(trim((string) $request->query('q')))->orderByDesc('updated_at');
        } else {
            self::scopeFor($preset, $q);
            match ($preset) {
                // Порядок прохода не меняется от правки поля: по заведению, новые сверху.
                'nofloor', 'unpriced', 'priced' => $q->orderByDesc('offers.id'),
                'slots' => $q->orderBy('slot_at')->orderByDesc('offers.id'),
                'published' => $admin ? self::byPick($q) : $q->orderByDesc('published_at'),
                default => $sort === 'number' ? $q->orderByDesc('number') : $q->orderByDesc('updated_at'),
            };
        }
        $facets->apply($q);
        // Числа вкладок — с тем же выбором, что и список (и «кроме Каркаде» по умолчанию).
        $counts = self::counts($request->user(), $facets);
        if ($carcadeHidden) {
            $facets->chips();
            $request->query->remove('vendor');
        }

        $count = $q->count();
        // Вид — на любой вкладке (начальник 04.10.2026: «вернуть кнопку видов»); по умолчанию в CRM таблица, поиск — таблицей.
        $view = $searching ? ListView::TABLE : ListView::pick($request, $count);
        $offers = ListView::isTable($view) ? $q->paginate(max($count, 1), total: $count)->withQueryString() : ListView::paginate($request, $q, $count);
        // Кадры нужны плиткам и строкам; в таблице их нет — кроме «Оцененных»: там по ним видно, готово ли к продаже.
        if (! ListView::isTable($view) || ($preset === 'priced' && ! $searching)) {
            $offers->loadMissing('media');
        }
        // Кто завёл — аватар только у черновика: остальным строкам люди не нужны.
        $offers->getCollection()->where('state', OfferState::Draft)->load('moderator.media');
        // «Оценить» с чипа или из закупки: first — первая строка, где у человека есть дело.
        if (Detail::key($request) === 'first') {
            $first = $offers->first(fn ($o) => self::todo($o, $request->user())) ?? $offers->first();

            return redirect($request->fullUrlWithQuery(['peek' => $first?->number]));
        }

        return view('admin.offers.index', [
            'offers' => $offers,
            'detail' => $detail,
            'view' => $view,
            'step' => $step,
            'searching' => $searching,
            'presets' => self::PRESETS,
            'sorts' => $preset === 'archive' ? self::SORTS : [],
            'preset' => $preset,
            // Вкладка по умолчанию у человека — у её пилюли ключа в адресе нет; остальные, и первая тоже, — с ключом.
            'home' => self::home($request->user()),
            'sort' => $sort,
            'facets' => $facets,
            // Галочки и отправка — админу, в «Оцененных» и «Публикации».
            'pick' => $admin && ! $searching && in_array($preset, ['priced', 'slots', 'archive'], true),
            'cols' => self::columns($preset, $request->user(), $searching),
            'counts' => $counts,
        ]);
    }

    /** Вендор Каркаде: на проде он «Carcade», локально «Каркаде» — как его ищет миграция закупок. */
    private static function carcadeId(): ?int
    {
        return Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->value('id');
    }

    /** Подставить в запрос «все, кроме Каркаде» (`!id` — исключение, Facets); Каркаде на вкладке нет — false. */
    private function hideCarcade(Request $request, string $preset): bool
    {
        $carcade = self::carcadeId();
        if (! $carcade || ! self::scopeFor($preset, Offer::visibleTo($request->user())->whereNot(fn ($o) => $o->emptyDraft()))->where('vendor_id', $carcade)->exists()) {
            return false;
        }
        $request->query->set('vendor', '!'.$carcade);

        return true;
    }

    /** Условие вкладки — одно место для списка, чисел у вкладок и таб-бара. */
    public static function scopeFor(string $preset, Builder $q): Builder
    {
        return match ($preset) {
            'nofloor' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('asking_price')->orWhere('asking_price', 0))
                ->where(fn ($p) => $p->whereNull('floor_price')->orWhere('floor_price', 0)),
            'unpriced' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('asking_price')->orWhere('asking_price', 0))->where('floor_price', '>', 0),
            'priced' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where('asking_price', '>', 0),
            'slots' => $q->scheduled(),
            'published' => $q->where('offers.state', OfferState::Open),
            default => $q->whereIn('offers.state', [OfferState::Archived, OfferState::Cancelled]),
        };
    }

    /**
     * Опубликованные — группами по тому, что делать: 0 «Выбрать» (приём закрыт, подтверждения есть), 1 «Идёт приём»
     * (скоро закроются — выше), 2 «Без подтверждений».
     */
    public const PICK_GROUPS = ['Выбрать', 'Идёт приём', 'Без подтверждений'];

    private static function byPick(Builder $q): Builder
    {
        $bids = "exists (select 1 from bids where bids.offer_id = offers.id and bids.state = '".BidState::Active->value."')";
        $group = "case when bids_close_at is not null and bids_close_at <= now() and {$bids} then 0 when bids_close_at is null or bids_close_at > now() then 1 else 2 end";

        return $q->orderByRaw($group)->orderByRaw("case when ({$group}) = 2 then null else bids_close_at end asc nulls last")->orderByDesc('bids_close_at');
    }

    /** Группа опубликованного (PICK_GROUPS) — то же правило, что у порядка. */
    public static function pickGroup(Offer $offer): int
    {
        if (! $offer->bids_close_at || $offer->bids_close_at->isFuture()) {
            return 1;
        }

        return $offer->active_bids_count ? 0 : 2;
    }

    /**
     * Столбцы таблицы по вкладке — только то, что на этом шаге что-то значит (владелец 04.10.2026: «в „Без цены“ не может
     * быть подтверждений»). Ключи: vendor — вендор и № убытка, no — № (у черновика — кто завёл; без него аватар — у даты),
     * state — приём или состояние, bids — подтверждения, floor — закупочная, price, created / published — когда заведено
     * / вышло. Порядок столбцов задаёт шаблон: закупочная перед ценой продажи.
     * null — все (поиск по разделу, галерея).
     *
     * @return list<string>|null
     */
    public static function columns(?string $preset, User $user, bool $searching = false): ?array
    {
        if ($searching || ! array_key_exists((string) $preset, self::PRESETS) && $preset !== null) {
            return null;
        }
        $admin = $user->canManageCrm();

        return match ($preset ?? 'unpriced') {
            // Закупочную заполняют — цены продажи здесь не бывает.
            'nofloor' => ['vendor', 'city', 'floor', 'created'],
            // Оценка: закупочная — ориентир, «Оценить» на месте цены; у черновика номера нет, кто завёл — аватаром у даты.
            // Город — сразу за номером убытка (владелец 04.10.2026: «не хватает столбца с городом»). Оценочной в таблице нет
            // (владелец 05.10.2026: «скрой») — она в карточке строки, в «Деньгах».
            'unpriced', 'priced' => ['vendor', 'city', 'floor', 'price', 'created'],
            // Когда выйдет — заголовок группы, слова в строке не нужно.
            'slots' => ['vendor', 'city', 'floor', 'price', 'created'],
            // Модератору подтверждения не видны, а «в продаже» здесь и так у всех.
            'published' => $admin ? ['vendor', 'city', 'state', 'bids', 'floor', 'price', 'published'] : ['vendor', 'city', 'floor', 'price', 'published'],
            default => ['vendor', 'city', 'state', 'floor', 'price', 'created'],
        };
    }

    /**
     * Числа у вкладок с чипами (их у «Без цены» нет — и число без них); «Рекомендуем» — только у опубликованных.
     *
     * @return array<string, int>
     */
    public static function counts(User $user, ?Facets $facets = null): array
    {
        $base = fn () => Offer::inCrm($user)->whereNot(fn ($o) => $o->emptyDraft());
        $counts = [];
        foreach (array_diff(array_keys(self::PRESETS), ['archive']) as $key) {
            $q = self::scopeFor($key, $base());
            // Без чипов (поток после «Оценить») «Без цены» считается как вид по умолчанию — без Каркаде.
            if (! $facets && in_array($key, self::TODO, true) && ($carcade = self::carcadeId())) {
                $q->where(fn ($v) => $v->whereNull('offers.vendor_id')->orWhere('offers.vendor_id', '!=', $carcade));
            }
            if ($facets) {
                $facets->applyTo($q, $key === 'published' ? null : 'recommended');
            }
            $counts[$key] = $q->count();
        }

        return $counts;
    }

    /** Чипы списка предложений — те же у галереи. */
    public static function facets(): array
    {
        return [Common::vendor('offers.vendor_id')->none('Без вендора'), Common::city('offers.settlement_id'), Common::category('offers.vehicle_category')];
    }

    public function store(Request $request, CreateOffer $create)
    {
        $offer = $create($request->user(), $this->carried($request->user()));

        return redirect("/offers/{$offer->number}");
    }

    /**
     * Вендор не спрашивается второй раз: новый черновик человека получает вендора его прошлого заведённого руками
     * (перенос из закупки и «Завести» из писем не в счёт — иначе после Carcade ручное встало бы на его маршрут) и НДС
     * цен этого вендора. Подряд заводят пачку от одной страховой.
     */
    private function carried(User $user): array
    {
        $vendorId = Offer::where('moderator_id', $user->id)->whereNotNull('vendor_id')->whereDoesntHave('purchaseCar')
            ->whereNotIn('id', Candidate::whereNotNull('offer_id')->select('offer_id'))->latest('id')->value('vendor_id');

        return $vendorId ? ['vendor_id' => $vendorId, 'prices_include_vat' => Vendor::offersVat($vendorId)] : [];
    }

    public function edit(Request $request, Offer $offer)
    {
        // Модератору — поля, фото, документы и история: подтверждений, интереса, сделки, маршрута, чатов и круга показа
        // он не видит, и грузить их незачем.
        $admin = $request->user()->canManageCrm();
        $offer->load(['brand', 'model', 'settlement', 'media', 'events.user', ...($admin ? ['bids.user', 'interests.user.manager', 'vendor.workflows', 'parkVehicle.yard', 'parkVehicle.requests',
            'positions.stage.block', 'positions.stage.exits.to', 'positions.stage.workflow', 'deal.buyer'] : [])]);
        // Давно закрытое предложение лежит в холодном слое без конверсий — досчитать, раз открыли.
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)) {
            app(WarmPhotos::class)($offer);
        }

        $threads = Thread::where('offer_id', $offer->id)->get();
        $fromMail = $offer->state === OfferState::Draft && session()->has("mail-draft.{$offer->id}");
        // Пустой «+ Новый»: первым — поле для текста про машину; уйдут, ничего не внеся, — черновика нет. Открыли снова
        // (в том числе обновили страницу) — отметка, чтобы удаление по прошлому уходу его не тронуло.
        $empty = ! $fromMail && $offer->state === OfferState::Draft && Offer::emptyDraft()->whereKey($offer->id)->exists();
        if ($empty) {
            Offer::whereKey($offer->id)->update(['updated_at' => now()]);
        }

        return view('admin.offers.edit', OfferFiles::letters($offer, $threads) + [
            'offer' => $offer,
            'admin' => $admin,
            'threads' => $threads,
            // ?window= — открыть окно писем сразу (ссылка «Вся переписка» из шторки документов).
            'window' => str_starts_with((string) request()->query('window'), "/offers/{$offer->number}/letters") ? request()->query('window') : null,
            'docs' => OfferFiles::docs($offer, $threads),
            'chats' => $admin ? Chat::with('user')->where('offer_id', $offer->id)->addSelect(['*', 'last_text' => ChatMessage::select('text')->whereColumn('chat_id', 'chats.id')->orderByDesc('seq')->limit(1)])->orderByDesc('last_message_at')->get() : collect(),
            'import' => ImportThreadFiles::progress($offer->id),
            'tags' => $admin ? Tag::orderBy('sort')->get() : collect(),
            'managers' => $admin ? User::withRole(Role::Manager)->orderBy('name')->get() : collect(),
            'audienceOptions' => $admin ? AudienceRules::options() : null,
            'showingSummary' => $admin ? Showing::summary($offer) : collect(),
            // Черновик только что заведён из писем и ещё ни разу не сохранён: внизу «Отменить» и «Не заявка».
            'fromMail' => $fromMail,
            'empty' => $empty,
            // Машина в гараже ведётся здесь же, карточкой «Гараж»: путь, деньги, расходы и все действия сотрудника.
            'garageView' => $admin ? self::garageView($offer, $request->user()) : null,
        ]);
    }

    /** Карточка «Гараж» в редакторе и в «Работе → Гараж»: что рисовать и какие действия доступны. */
    public static function garageView(Offer $offer, User $user): ?array
    {
        $car = GarageCar::with(['manager', 'costs.author', 'invoice', 'payoutInvoice', 'deal'])->where('offer_id', $offer->id)->first();
        if (! $car) {
            return null;
        }
        $car->setRelation('offer', $offer);
        $car->costs->each->setRelation('car', $car);

        return GarageView::for($car, $user);
    }

    /**
     * Шторка документов редактора: письмо вендора, документы предложения, файлы писем, которых среди них нет
     * (пока идёт импорт, документов ещё нет), фото.
     *
     * @param  Collection<int, Thread>  $threads
     * @return list<array<string, mixed>>
     */
    /**
     * Другие предложения с тем же VIN или номером убытка (twins_controller под полями): второе на ту же машину видно до
     * сохранения. Чужое модератору (опубликованное, из закупки) — строкой без ссылки: знать, что оно есть, надо.
     */
    public function twins(Request $request)
    {
        $vin = strtoupper(trim((string) $request->query('vin')));
        $claim = Code::key((string) $request->query('claim_ref'));
        $vin = strlen($vin) === 17 ? $vin : null;
        $offers = ! $vin && ! $claim ? collect() : Offer::with(['brand', 'model'])
            ->where(fn ($w) => $w->when($vin, fn ($w) => $w->where('vin', $vin))->when($claim, fn ($w) => $w->orWhere('claim_ref_key', $claim)))
            ->when($request->query('except'), fn ($q, $id) => $q->whereKeyNot((int) $id))->latest('id')->limit(3)->get();

        return view('admin.offers.twins', ['offers' => $offers, 'user' => $request->user()]);
    }

    /** Карточка строки рядом со списком (Detail): чужое модератору — как не найдено. */
    public function detailOf(Request $request, ?Offer $offer, bool $gallery = false)
    {
        return $offer && $offer->isEditableBy($request->user()) ? $this->detail($request, $offer, $gallery) : null;
    }

    /** Карточка строки: фото, метки, цена, действия, подтверждения, интерес; gallery — строка списка галереи. */
    public function detail(Request $request, Offer $offer, ?bool $gallery = null)
    {
        $admin = $request->user()->canManageCrm();
        $offer->load(['brand', 'model', 'settlement', 'media', ...($admin ? ['bids.user', 'interests.user.manager', 'deal', 'purchaseCar.offers.user', 'parkVehicle:id,offer_id,accepted_at,created_at', 'vendor.workflows', 'positions.stage', 'evacuator'] : [])])
            ->loadCount(['activeBids', 'interests'])->loadMax('activeBids as top_bid', 'amount');

        return view('admin.offers.detail', OfferFiles::letters($offer, Thread::where('offer_id', $offer->id)->get()) + [
            'offer' => $offer,
            'admin' => $admin,
            // Поля редактора в карточке — те же справочники, что у страницы; модератору денег, кроме закупочной, и круга нет.
            'audienceOptions' => $admin ? AudienceRules::options() : null,
            'tags' => $admin ? Tag::orderBy('sort')->get() : collect(),
            'chats' => $admin ? Chat::where('offer_id', $offer->id)->get(['id', 'unread_for_staff']) : collect(),
            'list' => $gallery ?? $request->boolean('gallery'),
            // Кому отдать в гараж — только у черновика без цены (блок «Оценить» / «В гараж»).
            'managers' => $admin && $offer->state === OfferState::Draft && ! $offer->asking_price ? User::withRole(Role::Manager)->orderBy('name')->get() : collect(),
        ]);
    }

    /**
     * «Сохранить» — и в список предложений; у черновика «Опубликовать» — та же форма с `then=open`: сначала поля, потом
     * в продажу; «+ Новый» (`then=next`) — сразу следующий черновик с тем же вендором: пачку заводят подряд.
     */
    public function update(OfferRequest $request, Offer $offer, UpdateOffer $update, ScheduleOffer $schedule, CreateOffer $create)
    {
        $unpriced = $offer->state === OfferState::Draft && ! $offer->asking_price;
        $hadFloor = (bool) $offer->floor_price;
        $update($offer, $request->payload(), $request->user());
        session()->forget("mail-draft.{$offer->id}");
        // Нажали знак Мигторга в поле номера убытка: несохранённые правки формы (вендор, цены) легли вместе с номером.
        if ($request->input('then') === 'migtorg') {
            return back()->with('toast', ImportMigtorgLot::take($offer->refresh(), $request->user()));
        }
        if ($request->input('then') === 'next') {
            $next = $create($request->user(), $this->carried($request->user()));

            return redirect("/offers/{$next->number}")->with('toast', 'Сохранено');
        }
        // «Сохранить» у черновика — и закрыть редактор: следующий шаг черновика в списке.
        if ($request->input('then') === 'close') {
            return redirect('/')->with('toast', 'Сохранено');
        }
        if ($request->input('then') === 'open' && $offer->state === OfferState::Draft && $request->user()->canManageCrm()) {
            $offer = $schedule($offer->refresh(), $this->when($request, Slots::NEAREST), $request->user());

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer));
        }

        // Сохранили из карточки, и строка больше не подходит под вкладку (заполнили закупочную — уехала в «Без продажной
        // цены», поставили цену — в «Оцененные», стёрли — назад): строка уходит, на вкладках дела карточка — к следующей.
        // Тихое сохранение перед другой кнопкой (save_bar, ajax) этого не делает.
        if (! $request->ajax() && ($gone = $this->gone($request, $offer->refresh()))) {
            return back()->with('toast', match (true) {
                $unpriced && (bool) $offer->asking_price => 'Оценено: '.Money::rub($offer->asking_price),
                ! $hadFloor && (bool) $offer->floor_price => 'Закупочная: '.Money::rub($offer->floor_price),
                default => 'Сохранено',
            })->with($gone);
        }

        // «Сохранить изменения» — остаёмся там же (04.10.2026: кнопка выезжает после правки, экран не меняется); из
        // карточки строки DetailBack вернёт в список с той же карточкой.
        return back()->with('toast', 'Сохранено');
    }

    /**
     * Ушли из редактора черновика, ничего не внеся (`drop_empty_controller`, sendBeacon): черновика вскоре нет
     * (`DropEmptyDraft`). Внесли хоть что-то, загрузили фото, пришли письма — сервер не тронет его, что бы ни прислал
     * браузер.
     */
    public function dropEmpty(Offer $offer)
    {
        if ($offer->state === OfferState::Draft) {
            DropEmptyDraft::dispatch($offer->id, now());
        }

        return response()->noContent();
    }

    /** Черновик из парковки завели в продажу зря: отвязать ТС и удалить («Снять с продажи» в шапке редактора). */
    public function unlist(Offer $offer, UnlistParkOffer $unlist)
    {
        $unlist($offer, request()->user());

        return redirect('/')->with('toast', 'Снято с продажи');
    }

    /** Продлить приём на ходу: от текущего срока, если он ещё не прошёл, иначе от сейчас. */
    public function extend(Request $request, Offer $offer, UpdateOffer $update)
    {
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'in:15,60']])['minutes'];
        $from = $offer->bids_close_at?->isFuture() ? $offer->bids_close_at : now();
        $offer = $update($offer, ['bids_close_at' => $from->copy()->addMinutes($minutes)], $request->user());

        return back()->with('toast', 'Приём до '.$offer->bids_close_at->translatedFormat('j M, H:i'));
    }

    /**
     * Отдать машину менеджеру в гараж, минуя подтверждение: с этапа «Ждёт страховую» — гаражной сделкой по маршруту
     * (`SendViaRoute`), с доставки, подготовки или продажи — сразу (`TakeToGarage`): машина бывает уже у нас или у него.
     */
    public function garage(Request $request, Offer $offer, TakeToGarage $take, SendViaRoute $send)
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in([CarState::Waiting->value, CarState::Delivery->value, CarState::Repair->value, CarState::Selling->value])],
            'manager_id' => [Rule::requiredIf($request->input('stage') === CarState::Waiting->value), 'nullable', 'exists:users,id'],
            'payer' => ['nullable', Rule::enum(GaragePayer::class)],
            'cost' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['manager_id.required' => 'Ждать страховую будет менеджер — выберите кому']);
        $manager = $data['manager_id'] ? User::findOrFail($data['manager_id']) : null;
        $stage = CarState::from($data['stage']);
        if ($stage === CarState::Waiting) {
            $send($offer, $manager, GaragePayer::tryFrom($data['payer'] ?? '') ?? GaragePayer::Us, $request->user());
        } else {
            $take($offer, $manager, $data['cost'] ?? null, $request->user(), $data['note'] ?? null, $stage);
        }

        // Из карточки «Без цены» (DetailBack): строка уходит из списка, карточка — к следующей без цены.
        return redirect("/offers/{$offer->number}")->with('toast', $manager ? 'В гараже у '.$manager->shortName() : 'В гараже')
            ->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', self::counts($request->user()));
    }

    /**
     * «Оценить» во вкладке «Без цены»: только цена продажи — машина уходит в «Оцененные», карточка переходит к следующей
     * без цены. Публикации здесь нет: в продажу отправляют пачкой из «Оцененных». Пустое поле — просто дальше.
     */
    public function rate(Request $request, Offer $offer, UpdateOffer $update)
    {
        $raw = (int) preg_replace('/\D+/', '', (string) ($request->validate(['asking_price' => ['nullable', 'string', 'max:20']])['asking_price'] ?? ''));
        if (! $raw || $offer->state !== OfferState::Draft) {
            return back()->with('detail-advance', true);
        }
        $update($offer, ['asking_price' => $raw], $request->user());

        return back()->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', self::counts($request->user()))
            ->with('toast', 'Оценено: '.Money::rub($raw));
    }

    /**
     * «Закупочная» сверху карточки во вкладке «Без закупочной цены» (05.10.2026): модератор вписал закупочную (у Альфы —
     * оценочную, закупочная посчитана на лету) — машина уходит в «Без продажной цены», карточка — к следующей. Пустое
     * поле — просто дальше.
     */
    public function floor(Request $request, Offer $offer, UpdateOffer $update)
    {
        $data = $request->validate(['floor_price' => ['nullable', 'string', 'max:20'], 'value' => ['nullable', 'string', 'max:20']]);
        $money = fn (?string $v) => (int) preg_replace('/\D+/', '', (string) $v) ?: null;
        $floor = $money($data['floor_price'] ?? null);
        $value = $money($data['value'] ?? null);
        if (! $floor || $offer->state !== OfferState::Draft) {
            return back()->with('detail-advance', true);
        }
        $update($offer, array_filter(['floor_price' => $floor, 'value' => $value]), $request->user());
        $gone = $this->gone($request, $offer->refresh());

        return back()->with('detail-advance', true)->with($gone)->with('toast', 'Закупочная: '.Money::rub($floor));
    }

    /**
     * «Отправить в продажу» пачкой из «Оцененных» и действия «Публикации»: сейчас, в ближайший или следующий слот (у
     * стоящего в слоте — перенос). Не готовое (нет фото) остаётся на месте, остальные уходят. «Сейчас» пачкой —
     * менеджеру одно «Опубликовано N» тиком часов, а не по уведомлению на каждое.
     */
    public function scheduleMany(Request $request, ScheduleOffer $schedule)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer'], 'when' => ['nullable', 'string']]);
        $when = $this->when($request, Slots::NEAREST);
        $offers = Offer::whereIn('number', $data['offers'])->get();
        $done = 0;
        $failed = [];
        foreach ($offers as $offer) {
            try {
                $schedule($offer, $when, $request->user(), batch: $offers->count() > 1);
                $done++;
            } catch (ValidationException $e) {
                $failed[] = $offer->titleWithYear().', нет '.str_replace(['Для публикации не хватает: ', 'фотографии'], ['', 'фото'], (string) collect($e->errors())->flatten()->first());
            }
        }
        $at = Slots::at($when);
        $toast = $done ? ($at ? 'Выйдут '.Slots::phrase($at).': '.$done : 'Опубликовано: '.$done) : null;
        $back = back(fallback: '/?preset=priced');

        return $failed
            ? $back->with('toast-danger', ($toast ? $toast.'. ' : '').'Не вышли '.count($failed).': '.implode('; ', array_slice($failed, 0, 3)))
            : $back->with('toast', $toast);
    }

    /** «Убрать из слота» пачкой: машины возвращаются в «Оцененные». */
    public function unscheduleMany(Request $request, UnscheduleOffer $unschedule)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']]);
        $offers = Offer::whereIn('number', $data['offers'])->get();
        $offers->each(fn ($o) => $unschedule($o, $request->user()));

        return back(fallback: '/?preset=slots')->with('toast', 'Убрано из слота: '.$offers->count());
    }

    /** «Удалить навсегда» из архива — одно предложение (карточка, редактор) со всем связанным (`PurgeOffer`). */
    public function purge(Offer $offer, PurgeOffer $purge)
    {
        $title = $offer->titleWithYear();
        $purge($offer);

        return redirect('/?preset=archive')->with('toast', 'Удалено навсегда: '.$title);
    }

    /** «Удалить навсегда» пачкой из вкладки «Архив»: не из архива (успели вернуть) пропускается. */
    public function purgeMany(Request $request, PurgeOffer $purge)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']]);
        $done = 0;
        foreach (Offer::whereIn('number', $data['offers'])->whereIn('state', [OfferState::Archived, OfferState::Cancelled])->get() as $offer) {
            $purge($offer);
            $done++;
        }

        return redirect('/?preset=archive')->with('toast', 'Удалено навсегда: '.$done);
    }

    /** Состояние из меню; «Опубликовать» у черновика и галереи — с выбором слота (`when`), без него — сейчас. */
    public function state(Request $request, Offer $offer, ChangeOfferState $change, ScheduleOffer $schedule)
    {
        $next = OfferState::from($request->validate(['state' => ['required', 'string']])['state']);
        // В сделку руками нельзя: сделку заводит «Принять» у подтверждения.
        if ($next === OfferState::Sold) {
            return back()->withErrors(['state' => 'Примите подтверждение менеджера — сделка заведётся сама']);
        }
        if ($next === OfferState::Open && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            $offer = $schedule($offer, $this->when($request, Slots::NOW), $request->user());

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer))->with($this->gone($request, $offer));
        }
        $change($offer, $next, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', $next->label())->with($this->gone($request, $offer));
    }

    /**
     * Сменили состояние из карточки рядом со списком (в архив, опубликовали, вернули в черновики): строка ушла из
     * открытой вкладки — убрать её сразу и поправить числа пилюль (`detail-gone`, как после «Оценить»), а не ждать
     * обновления страницы (владелец 05.10.2026). Вкладку и галерею берём из адреса списка; в поиске строка остаётся.
     */
    private function gone(Request $request, Offer $offer): array
    {
        $ref = parse_url((string) $request->header('Referer'));
        parse_str($ref['query'] ?? '', $q);
        if (! isset($q['peek']) || filled($q['q'] ?? null)) {
            return [];
        }
        $preset = array_key_exists((string) ($q['preset'] ?? ''), self::PRESETS) ? (string) $q['preset'] : self::home($request->user());
        $still = match ($ref['path'] ?? '/') {
            '/' => self::scopeFor($preset, Offer::whereKey($offer->id))->exists(),
            '/gallery' => $offer->fresh()?->state === OfferState::Gallery,
            default => true,
        };

        // На вкладках дела карточка идёт к следующей строке, на остальных строка просто уходит.
        return $still ? [] : ['detail-gone' => true, 'detail-counts' => self::counts($request->user())]
            + (($ref['path'] ?? '/') === '/' && in_array($preset, self::TODO, true) ? ['detail-advance' => true] : []);
    }

    /** «Убрать из слота»: остаётся черновиком или галереей. */
    public function unschedule(Request $request, Offer $offer, UnscheduleOffer $unschedule)
    {
        $unschedule($offer, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Убрано из слота');
    }

    private function when(Request $request, string $default): string
    {
        return in_array($when = (string) $request->input('when', $default), Slots::WHEN, true) ? $when : $default;
    }

    /** Тост публикации: номер — уже публичный, у поставленного в слот — когда выйдет. */
    private function published(Offer $offer): string
    {
        return $offer->isScheduled() ? 'Выйдет '.Slots::phrase($offer->slot_at) : 'В продаже № '.$offer->number;
    }
}
