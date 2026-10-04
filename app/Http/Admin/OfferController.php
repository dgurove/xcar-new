<?php

namespace App\Http\Admin;

use App\Chats\Chat;
use App\Chats\Message as ChatMessage;
use App\Garage\Actions\SendViaRoute;
use App\Garage\Actions\TakeToGarage;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Thread;
use App\Media\Actions\WarmPhotos;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Actions\UnlistParkOffer;
use App\Offers\Actions\UnscheduleOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\AudienceRules;
use App\Offers\BidState;
use App\Offers\Jobs\DropEmptyDraft;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Offers\Slots;
use App\Offers\Tag;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class OfferController
{
    /**
     * Опубликованное и неопубликованное вместе не показываются никогда (04.10.2026, владелец): пилюли «Все» нет, каждая —
     * одна сторона: в продаже (и её выборки) или черновики. Сделки в предложениях не показываются — они в «Работе».
     */
    public const PRESETS = [
        'open' => 'В продаже', 'bids' => 'Выбрать', 'recommended' => 'Рекомендуем', 'draft' => 'Черновики', 'slot' => 'В слоте',
        'archive' => 'Архив', 'purchase' => 'Из закупок',
    ];

    /** Модератору — предложения его группы по состоянию: выбирать победителя и рекомендовать — дело админа. */
    public const MODERATOR_PRESETS = ['draft' => 'Черновики', 'open' => 'В продаже', 'archive' => 'Архив'];

    public const SORTS = ['fresh' => 'Сначала новые', 'bids' => 'По подтверждениям', 'closing' => 'Скоро закроются', 'number' => 'По номеру'];

    public function index(Request $request)
    {
        // ?peek=номер — карточка строки рядом (first — первый неоценённый черновик страницы, адрес получает его номер ниже).
        $detail = Detail::of($request, fn (string $key) => $this->detailOf($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $admin = $request->user()->canManageCrm();
        $presets = $admin ? self::PRESETS : self::MODERATOR_PRESETS;
        // Без пилюли: админу — то, что в продаже, модератору — его черновики.
        $preset = array_key_exists((string) $request->query('preset'), $presets) ? (string) $request->query('preset') : ($admin ? 'open' : 'draft');
        // «В слоте» — пилюлей, пока есть что выпускать в 16:00.
        $scheduled = $admin ? Offer::scheduled()->whereDoesntHave('purchaseCar')->count() : 0;
        if ($admin && ! $scheduled && $preset !== 'slot') {
            unset($presets['slot']);
        }
        $facets = Facets::for($request, 'crm-offers', ...self::facets());
        // Таблица — вид по умолчанию (04.10.2026, владелец): выбор «строками» и обратно помнится.
        ListPrefs::sync($request, 'crm-offers', rememberTable: true, keep: $facets->keys());
        $sort = $request->query('sort', 'fresh');
        // Поиск лупой идёт по всему списку — мимо пилюли и чипов.
        $searching = $facets->searching();

        // Пустой «+ Новый» в списке не стоит: его либо заполнят, либо он удалится, как только из него уйдут.
        $q = Offer::query()->visibleTo($request->user())->whereNot(fn ($o) => $o->emptyDraft())->with(['brand', 'model', 'settlement', 'parkVehicle:id,offer_id,category,accepted_at,created_at'])
            ->withCount(['activeBids', 'interests'])->withMax('activeBids as top_bid', 'amount');

        if (! $searching) {
            match ($preset) {
                'recommended' => $q->where('recommended', true)->where('state', OfferState::Open),
                // У админа поставленные в слот — своей пилюлей, в «Черновиках» их нет; модератору это всё ещё черновики.
                'draft' => $q->where('state', OfferState::Draft)->when($admin, fn ($d) => $d->whereNull('slot_at')),
                'slot' => $q->scheduled(),
                'open' => $q->where('state', OfferState::Open),
                // Выбрать победителя: в продаже и с подтверждениями. У предложения в сделке оставшиеся — резерв, не очередь.
                'bids' => $q->where('state', OfferState::Open)->whereHas('bids', fn ($b) => $b->where('state', BidState::Active)),
                'archive' => $q->whereIn('state', [OfferState::Archived, OfferState::Cancelled]),
                // Из закупок — неопубликованные своей пилюлей (их десятки); опубликованные — в «В продаже» со всеми.
                'purchase' => $q->whereHas('purchaseCar')->where('state', OfferState::Draft),
            };
        }
        // Предложения из закупок (контрпредложение Carcade → «В предложения») по умолчанию скрыты — их смотрят
        // своей пилюлей. Модератор их не видит вовсе (`Offer::scopeVisibleTo`).
        if (in_array($preset, ['draft', 'slot'], true) && ! $searching) {
            $q->whereDoesntHave('purchaseCar');
        }
        if ($searching) {
            $term = trim((string) $request->query('q'));
            $q->searchCrm($term);
        }
        $facets->apply($q);
        // В «Выбрать» сначала те, у кого приём уже закрыт, — по ним решать сейчас.
        if ($preset === 'bids' && ! $request->has('sort')) {
            $sort = 'closing';
        }
        match ($sort) {
            'bids' => $q->orderByDesc('active_bids_count')->orderByRaw('top_bid desc nulls last')->orderByDesc('updated_at'),
            'closing' => $q->orderByRaw('bids_close_at asc nulls last'),
            'number' => $q->orderByDesc('number'),
            default => $q->orderByDesc('updated_at'),
        };

        $offers = ListView::paginate($request, $q);
        // Кадры нужны плиткам и строкам, в таблице их нет — там это лишние сотни записей медиатеки.
        if (! ListView::isTable(ListView::pick($request, $offers->total()))) {
            $offers->loadMissing('media');
        }
        // Кто завёл — аватар только у черновика: остальным строкам люди не нужны.
        $offers->getCollection()->where('state', OfferState::Draft)->load('moderator.media');
        // «Оценить» ведёт по черновикам: first — первый неоценённый черновик страницы, а нет таких — первая строка.
        if (Detail::key($request) === 'first') {
            $first = $offers->first(fn ($o) => $o->state === OfferState::Draft && ! $o->asking_price && $o->brand_id) ?? $offers->first();

            return redirect($request->fullUrlWithQuery(['peek' => $first?->number]));
        }

        return view('admin.offers.index', [
            'offers' => $offers,
            'detail' => $detail,
            'presets' => $presets,
            'sorts' => $admin ? self::SORTS : [],
            'preset' => $preset,
            'sort' => $sort,
            // Числа у пилюль; модератору — в пределах его группы.
            'facets' => $facets,
            'counts' => array_map(fn ($c) => $c instanceof Builder ? $facets->applyTo($c)->count() : $c, $admin ? [
                'recommended' => Offer::where('recommended', true)->where('state', OfferState::Open),
                'draft' => Offer::where('state', OfferState::Draft)->whereNull('slot_at')->whereNot(fn ($o) => $o->emptyDraft())->whereDoesntHave('purchaseCar'),
                'slot' => $scheduled,
                'bids' => Offer::where('state', OfferState::Open)->whereHas('bids', fn ($b) => $b->where('state', BidState::Active)),
                'purchase' => Offer::whereHas('purchaseCar')->where('state', OfferState::Draft),
            ] : ['draft' => Offer::visibleTo($request->user())->where('state', OfferState::Draft)->whereNot(fn ($o) => $o->emptyDraft())]),
        ]);
    }

    /** Чипы списка предложений — те же у галереи. */
    public static function facets(): array
    {
        return [Common::vendor('offers.vendor_id'), Common::city('offers.settlement_id'), Common::category('offers.vehicle_category')];
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
            'managers' => $admin ? User::where('role', Role::Manager)->orderBy('name')->get() : collect(),
            'audienceOptions' => $admin ? AudienceRules::options() : null,
            'showingSummary' => $admin ? Showing::summary($offer) : collect(),
            // Черновик только что заведён из писем и ещё ни разу не сохранён: внизу «Отменить» и «Не заявка».
            'fromMail' => $fromMail,
            'empty' => $empty,
        ]);
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
        $offer->load(['brand', 'model', 'settlement', 'media', ...($admin ? ['bids.user', 'interests.user.manager', 'deal', 'purchaseCar.offers.user', 'parkVehicle:id,offer_id,accepted_at,created_at'] : [])])
            ->loadCount(['activeBids', 'interests'])->loadMax('activeBids as top_bid', 'amount');

        return view('admin.offers.detail', OfferFiles::letters($offer, Thread::where('offer_id', $offer->id)->get()) + [
            'offer' => $offer,
            'admin' => $admin,
            // Поля редактора в карточке — те же справочники, что у страницы; модератору денег, кроме закупочной, и круга нет.
            'audienceOptions' => $admin ? AudienceRules::options() : null,
            'tags' => $admin ? Tag::orderBy('sort')->get() : collect(),
            'chats' => $admin ? Chat::where('offer_id', $offer->id)->get(['id', 'unread_for_staff']) : collect(),
            'list' => $gallery ?? $request->boolean('gallery'),
        ]);
    }

    /**
     * «Сохранить» — и в список предложений; у черновика «Опубликовать» — та же форма с `then=open`: сначала поля, потом
     * в продажу; «+ Новый» (`then=next`) — сразу следующий черновик с тем же вендором: пачку заводят подряд.
     */
    public function update(OfferRequest $request, Offer $offer, UpdateOffer $update, ScheduleOffer $schedule, CreateOffer $create)
    {
        $update($offer, $request->payload(), $request->user());
        session()->forget("mail-draft.{$offer->id}");
        if ($request->input('then') === 'next') {
            $next = $create($request->user(), $this->carried($request->user()));

            return redirect("/offers/{$next->number}")->with('toast', 'Сохранено');
        }
        if ($request->input('then') === 'open' && $offer->state === OfferState::Draft && $request->user()->canManageCrm()) {
            $offer = $schedule($offer->refresh(), $this->when($request, Slots::NEAREST), $request->user());

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer));
        }

        // «Сохранить» — обратно в список предложений (владелец 02.10.2026): правку сделали, дальше следующее.
        return redirect('/')->with('toast', 'Сохранено');
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

        return redirect("/offers/{$offer->number}")->with('toast', 'В гараже');
    }

    /**
     * «Оценить» черновик из карточки: цена продажи и в продажу — сейчас или в слот (по умолчанию ближайший), карточка
     * само переходит к следующему черновику без цены. Пустое поле — просто дальше. Не хватает для публикации (фото
     * ещё едут) — цена остаётся, ошибка в карточке.
     */
    public function publish(Request $request, Offer $offer, UpdateOffer $update, ScheduleOffer $schedule)
    {
        $raw = preg_replace('/\D+/', '', (string) ($request->validate(['asking_price' => ['nullable', 'string', 'max:20']])['asking_price'] ?? ''));
        if ($raw === '' || $offer->state !== OfferState::Draft) {
            return back()->with('detail-advance', true);
        }
        $update($offer, ['asking_price' => (int) $raw], $request->user());
        $offer = $schedule($offer->refresh(), $this->when($request, Slots::NEAREST), $request->user());

        return back()->with('detail-advance', true)->with('toast', $this->published($offer));
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

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer));
        }
        $change($offer, $next, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', $next->label());
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
