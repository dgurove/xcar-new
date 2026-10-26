<?php

namespace App\Http\Admin;

use App\Mail\Account;
use App\Mail\Scope;
use App\Offers\Actions\SendClaimRequest;
use App\Offers\Actions\SyncViewers;
use App\Offers\AudienceRules;
use App\Offers\Binding;
use App\Offers\Export\OffersXlsx;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Tag;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Plural;
use App\Support\Sort;
use App\Support\Surface;
use App\Users\Section;
use App\Vendors\Actions\SetLogo;
use App\Vendors\ClaimRequest;
use App\Vendors\DealFormat;
use App\Vendors\Kind;
use App\Vendors\Parser;
use App\Vendors\RewardKind;
use App\Vendors\Vendor;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Вендоры в CRM — продажа предложений: условия сделки (формат, вознаграждение, срок ответа, «держим», «молчание =
 * покупка», НДС цен предложений), почта продажи (ящик offer@/deal@, адреса и разбор писем с предложениями),
 * контакты «Реализация» и маршруты. Имя, тип и «работаем» — общие, правятся на обеих сторонах. Всё парковочное —
 * реквизиты, договор, хранение, прайс, деньги, заявки на приёмку — на парковке (`App\Http\Park\VendorController`).
 */
class VendorController
{
    public const PILLS = ['overview' => 'Обзор', 'offers' => 'Предложения', 'requests' => 'Запросы', 'contacts' => 'Контакты', 'routes' => 'Маршруты'];

    private const BIDS_SQL = 'exists (select 1 from bids where bids.offer_id = offers.id)';

    private const INTEREST_SQL = 'exists (select 1 from interests where interests.offer_id = offers.id)';

    /** Предложения вендора: по заведению, новые сверху; откликами и ценами — выбором и заголовком. */
    public const OFFER_SORTS = [
        'created' => ['Заведено', 'desc'], 'bids' => ['Подтверждения', 'desc'], 'interest' => ['Интерес', 'desc'],
        'floor' => ['Закупочная', 'desc'], 'price' => ['Цена продажи', 'desc'], 'requested' => ['Запрос', 'desc'],
        'binding' => ['Срок обязывающего', 'asc'],
    ];

    /** Справочник — по имени (владелец 06.10.2026), работающие первыми; дата и число предложений — выбором. */
    public const SORTS = ['name' => ['Имя', 'asc'], 'created' => ['Дата добавления', 'desc'], 'offers' => ['Предложения', 'desc']];

    /** Пилюли карточки до разделения, уехавшие на парковку: старые ссылки ведут туда. */
    private const PARK_PILLS = ['tariffs', 'money'];

    public function index(Request $request)
    {
        $kind = Kind::tryFrom($request->query('kind', ''));
        $off = $request->boolean('off');
        // Вендор парковки, который в CRM — другой (`crm_vendor_id`), в списке CRM не стоит.
        ListPrefs::sync($request, 'crm-vendors');
        $sort = Sort::from($request->query('sort'), self::SORTS, 'name');
        $q = Vendor::with('workflows')->withCount('offers')->whereNull('crm_vendor_id')->orderByDesc('is_active');
        match ($sort->key) {
            'created' => $q->orderBy('created_at', $sort->dir()),
            'offers' => $q->orderBy('offers_count', $sort->dir()),
            default => $q->orderBy('name', $sort->dir()),
        };
        $q->orderBy('id');
        if ($off) {
            $q->where('is_active', false);
        } elseif ($kind) {
            $q->where('kind', $kind)->where('is_active', true);
        }

        return view('admin.vendors.index', [
            'vendors' => $q->get(),
            'kind' => $kind,
            'off' => $off,
            'counts' => Vendor::where('is_active', true)->whereNull('crm_vendor_id')->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'offCount' => Vendor::where('is_active', false)->count(),
            'sort' => $sort,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['nullable', Rule::enum(Kind::class)],
        ]);
        // Тот же вендор под другим регистром — не второй: в CRM вендор один на обе стороны (`Vendor::named`).
        $vendor = Vendor::findOrCreateByName($data['name'], ['kind' => $data['kind'] ?? null]);
        if (! $vendor->wasRecentlyCreated) {
            return back()->withInput()->withErrors(['name' => 'Такой вендор уже есть']);
        }

        return redirect("/settings/vendors/{$vendor->id}");
    }

    public function show(Request $request, Vendor $vendor)
    {
        if (in_array($request->query('pill'), self::PARK_PILLS, true)) {
            return redirect()->away(Surface::Park->url("/vendors/{$vendor->id}?pill={$request->query('pill')}"), 301);
        }
        $pill = array_key_exists($request->query('pill', ''), self::PILLS) ? $request->query('pill') : 'overview';
        // «Запросы» — у вендора, у которого был отклик или запрос; бейдж — сколько номеров ждут отправки.
        $pending = ClaimRequest::pendingFor($vendor)->count();
        $requests = $pending > 0 || $pill === 'requests' || ClaimRequest::where('vendor_id', $vendor->id)->exists()
            || Offer::where('offers.vendor_id', $vendor->id)->whereRaw(ClaimRequest::REPLY_SQL)->exists();
        $data = [
            'vendor' => $vendor,
            'pill' => $pill,
            'pills' => $requests ? self::PILLS : array_diff_key(self::PILLS, ['requests' => 1]),
            'pendingCount' => $pending,
            'base' => "/settings/vendors/{$vendor->id}",
            // Ссылка на парковочную карточку — только тем, кого туда пустят.
            'park' => $request->user()->canAccess(Section::Park) ? Surface::Park->url("/vendors/{$vendor->id}") : null,
            'audienceOptions' => AudienceRules::options(),
            'accounts' => Account::where('scope', Scope::Offers)->sending()->orderBy('title')->get()->mapWithKeys(fn ($a) => [$a->id => $a->title.' ('.$a->email.')']),
        ];

        if ($pill === 'contacts') {
            $vendor->load('contacts');
        } elseif ($pill === 'routes') {
            $track = Track::tryFrom($request->query('track', '')) ?? Track::Sale;
            $workflow = $vendor->workflowOrNew($track);
            $workflow->load(['blocks.stages.exits.to', 'blocks.stages.block']);
            $data += [
                'track' => $track,
                'workflow' => $workflow,
                'problems' => $workflow->problems(),
                'occupied' => Position::whereIn('stage_id', $workflow->stages()->pluck('workflow_stages.id'))
                    ->selectRaw('stage_id, count(*) as n')->groupBy('stage_id')->pluck('n', 'stage_id'),
            ];
        } elseif ($pill === 'offers') {
            [$q, $facets, $sort] = $this->offersQuery($request, $vendor);
            $data += [
                'offers' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
                'facets' => $facets,
                'sort' => $sort,
                'searching' => $facets->searching(),
            ];
        } elseif ($pill === 'requests') {
            $history = ClaimRequest::where('vendor_id', $vendor->id)->with('user')->latest()->latest('id')->limit(60)->get();
            $refs = $history->pluck('refs')->flatten()->unique()->values()->all();
            $data += [
                'pending' => ClaimRequest::pendingFor($vendor)->with('bids:id,offer_id,state')->withCount(['bids', 'interests'])
                    ->orderByDesc('offers.created_at')->orderByDesc('offers.id')->get(),
                'history' => $history,
                // Машины номеров истории — где они сейчас и вернули ли номер в «К запросу».
                'sent' => $refs ? Offer::where('offers.vendor_id', $vendor->id)->whereHas('car', fn ($c) => $c->whereIn('claim_ref', $refs))
                    ->with('bids:id,offer_id,state')->get()->keyBy('claim_ref') : collect(),
                'open' => $request->integer('open'),
            ];
        } else {
            $vendor->load('mailAccount');
        }
        $data['offersTotal'] = Offer::where('vendor_id', $vendor->id)->count();

        return view('admin.vendors.show', $data);
    }

    /** Окно «Выгрузка» в «Предложениях» вендора: то, что на экране, с теми же чипами и поиском — файлом или в буфер. */
    public function export(Request $request, Vendor $vendor)
    {
        [$q] = $this->offersQuery($request, $vendor);

        return OffersXlsx::respond($request, $request->user(), 'vendor', $q, $vendor->name.' предложения');
    }

    /**
     * «Отправить N номеров» в «Запросах»: запрос со снимком номеров, у предложений — ссылка на него, номера отправившему в
     * Telegram (в буфер их кладёт браузер в момент нажатия). Берутся только отмеченные из «К запросу» этого вендора.
     */
    public function request(Request $request, Vendor $vendor, SendClaimRequest $send)
    {
        $sent = $send($vendor, (array) $request->input('offers', []), $request->user());
        $n = $sent ? count($sent->refs) : 0;
        $toast = match (true) {
            $n === 0 => 'Эти номера уже отправлены',
            ! $request->user()->telegram_chat_id => 'Отправлено '.$n.' '.Plural::of($n, ['номер', 'номера', 'номеров']).', Telegram не привязан',
            default => 'Отправлено '.$n.' '.Plural::of($n, ['номер', 'номера', 'номеров']).', они в буфере и в Telegram',
        };

        return redirect("/settings/vendors/{$vendor->id}?pill=requests")->with('toast', $toast);
    }

    /** Прислать номера прошлого запроса в Telegram ещё раз. */
    public function resend(Request $request, Vendor $vendor, ClaimRequest $claimRequest, SendClaimRequest $send)
    {
        abort_unless($claimRequest->vendor_id === $vendor->id, 404);

        return back()->with('toast', $send->telegram($claimRequest, $request->user()) ? 'Номера в Telegram' : 'Telegram не привязан');
    }

    /** Вернуть номер в «К запросу». */
    public function unrequest(Request $request, Vendor $vendor, Offer $offer, SendClaimRequest $send)
    {
        abort_unless($offer->vendor_id === $vendor->id, 404);
        $send->giveBack($offer, $request->user());

        return back()->with('toast', 'Номер снова в «К запросу»');
    }

    /**
     * Все предложения вендора за всё время, архив тоже (15.10.2026, владелец: «выгрузить номера убытка Т-Страхования, по
     * которым проявляли интерес или есть хоть одно подтверждение» — короткий список «Обзора» был бесполезен). Чипы:
     * «Отклик» — было ли подтверждение (любое, и отклонённое) или интерес; «Где сейчас» — состояние; «Заведено» — месяц.
     *
     * @return array{0: Builder, 1: Facets, 2: Sort}
     */
    private function offersQuery(Request $request, Vendor $vendor): array
    {
        $facets = Facets::for($request, 'crm-vendor-offers', self::replyFacet(),
            Facet::column('state', 'Где сейчас', ['состояние', 'состояния', 'состояний'], 'offers.state')->enum(OfferState::class)->natural(),
            Facet::column('requested', 'Запрос', [], "case when offers.claim_request_id is null then 'no' else 'yes' end")->natural()
                ->labels(fn (array $keys) => collect(['yes' => 'Отправлен', 'no' => 'Не отправлен'])->only($keys)->map(fn ($l, $k) => new Option($k, $l))->all()),
            // Вендор сам прислал письмом (16.10.2026) — такие не запрашивают.
            Facet::column('letter', 'Письмо', [], 'case when '.ClaimRequest::LETTER_SQL." then 'yes' else 'no' end")->natural()
                ->labels(fn (array $keys) => collect(['yes' => 'Прислали письмом', 'no' => 'Без письма'])->only($keys)->map(fn ($l, $k) => new Option($k, $l))->all()),
            // Срок обязывающего (19.10.2026): горит, вышел, есть, нет — те же корзины, что в логистике.
            Facet::column('binding', 'Срок обязывающего', ['вид', 'вида', 'видов'], Binding::bucketCase())->natural()
                ->labels(fn (array $keys) => collect(Binding::BUCKETS)->only($keys)->map(fn ($l, $k) => new Option($k, $l))->all()),
            // «Заведено» — промежуток дат, как в «Предложениях» CRM (21.10.2026, был чип месяцев); не запоминается.
            Facet::dates('created', 'Заведено', 'offers.created_at'),
        );
        ListPrefs::sync($request, 'crm-vendor-offers', keep: array_values(array_diff($facets->keys(), OfferController::ONCE)));
        $sort = Sort::from($request->query('sort'), self::OFFER_SORTS, '-created');
        $q = Offer::where('offers.vendor_id', $vendor->id)->with(['bids:id,offer_id,state', 'claimRequest:id,created_at'])
            ->withCount(['bids', 'interests'])->withMax('bids as best_bid', 'amount')->selectRaw(ClaimRequest::LETTER_SQL.' as by_letter');
        // Горит ли срок обязывающего — одним подзапросом (`Binding::select`), строка красит дату.
        Binding::select($q);
        if ($facets->searching()) {
            $q->searchCrm(trim((string) $request->query('q')));
        }
        $facets->apply($q);
        $dir = $sort->dir();
        match ($sort->key) {
            'bids' => $q->orderBy('bids_count', $dir),
            'interest' => $q->orderBy('interests_count', $dir),
            'floor' => $q->orderByRaw("offers.floor_price {$dir} nulls last"),
            'price' => $q->orderByRaw("offers.asking_price {$dir} nulls last"),
            'requested' => $q->orderByRaw("(select created_at from claim_requests where claim_requests.id = offers.claim_request_id) {$dir} nulls last"),
            'binding' => $q->orderByRaw("offers.binding_until {$dir} nulls last"),
            default => $q->orderBy('offers.created_at', $dir),
        };

        return [$q->orderByDesc('offers.id'), $facets, $sort];
    }

    /**
     * «Отклик» — галками: подтверждения, интерес, без отклика; отмеченное — через «или». Подтверждение — любое, хоть раз
     * поданное (и отклонённое, и отозванное), интерес — любой.
     */
    private static function replyFacet(): Facet
    {
        [$bids, $interest] = [self::BIDS_SQL, self::INTEREST_SQL];
        $sql = ['bids' => $bids, 'interest' => $interest, 'quiet' => "not {$bids} and not {$interest}"];
        $labels = ['bids' => 'Подтверждения', 'interest' => 'Интерес', 'quiet' => 'Без отклика'];

        return Facet::custom('response', 'Отклик', ['отклик', 'отклика', 'откликов'],
            fn (Builder $q, array $keys) => $q->where(fn ($w) => collect($keys)->filter(fn ($k) => isset($sql[$k]))->each(fn ($k) => $w->orWhereRaw("({$sql[$k]})"))),
            fn (Builder $q) => array_map('intval', (array) DB::table('offers')->whereIn('id', Facet::bare($q->toBase())->select('offers.id'))
                ->selectRaw(collect($sql)->map(fn ($s, $k) => "count(*) filter (where {$s}) as \"{$k}\"")->implode(', '))->first()),
        )->natural()->labels(fn (array $keys) => collect($labels)->only($keys)->map(fn ($l, $k) => new Option($k, $l))->all())
            ->caption(fn (array $keys) => $keys === ['bids', 'interest'] ? 'Подтверждения или интерес' : null);
    }

    public function update(Request $request, Vendor $vendor, SetLogo $setLogo, SyncViewers $sync)
    {
        $data = $request->validate(Vendor::rules($vendor) + [
            'deal_format' => ['required', Rule::enum(DealFormat::class)],
            'reward_kind' => ['nullable', Rule::enum(RewardKind::class)],
            'reward_value' => ['nullable', 'integer', 'min:0'],
            'answer_hours' => ['nullable', 'integer', 'between:1,720'],
            'binding_days' => ['nullable', 'integer', 'between:1,365'],
            'silence_means_buy' => ['boolean'],
            'offers_include_vat' => ['boolean'],
            'floor_by_value' => ['boolean'],
            'to_gallery' => ['boolean'],
            'letters_to_telegram' => ['boolean'],
            'pickup_on_arrival' => ['boolean'],
            'claim_ref_optional' => ['boolean'],
            'audience_rules' => ['nullable', 'json'],
            // Ящик продажи — только из ящиков CRM: парковочный отсюда не выбрать.
            'mail_account_id' => ['nullable', Rule::exists('mail_accounts', 'id')->where('scope', Scope::Offers->value)->whereNull('reply_account_id')],
            'senders' => ['nullable', 'string', 'max:2000'],
            'parser' => ['required', Rule::enum(Parser::class)],
        ] + SetLogo::RULES);
        $senders = Vendor::parseSenders($data['senders'] ?? null);
        if ($taken = Vendor::takenSender($senders, Scope::Offers, $vendor)) {
            return back()->withInput()->withErrors(['senders' => $taken]);
        }
        // Волны показа: «всем сразу» — то же, что ничего (AudienceRules::of по умолчанию).
        $rules = AudienceRules::normalize($request->input('audience_rules'));
        $vendor->update(array_merge($data, [
            'audience_rules' => $request->filled('audience_rules') ? ($rules === AudienceRules::everyone() ? null : $rules) : $vendor->audience_rules,
            'senders' => $senders,
            'is_active' => $request->boolean('is_active'),
            'silence_means_buy' => $request->boolean('silence_means_buy'),
            'offers_include_vat' => $request->boolean('offers_include_vat'),
            'floor_by_value' => $request->boolean('floor_by_value'),
            'to_gallery' => $request->boolean('to_gallery'),
            'letters_to_telegram' => $request->boolean('letters_to_telegram'),
            'pickup_on_arrival' => $request->boolean('pickup_on_arrival'),
            'claim_ref_optional' => $request->boolean('claim_ref_optional'),
        ]));

        // Метки по умолчанию из шторки «Продажа» (11.10.2026): та же связь, что «Ставится вендорам» у метки.
        if ($request->boolean('_tags')) {
            $vendor->defaultTags()->sync(Tag::whereKey(array_map('intval', (array) $request->input('tags', [])))->pluck('id')->all());
        }
        $setLogo($vendor, $request);
        // Волны вендора сменились — предложения без своих правил видят теперь другие.
        if ($vendor->wasChanged('audience_rules')) {
            $sync->all();
        }

        return back()->with('toast', 'Сохранено');
    }
}
