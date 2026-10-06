<?php

namespace App\Http\Admin;

use App\Offers\Actions\UpdateDealMoney;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\OfferFiles;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Workflow\WaitsFor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DealController
{
    public const PRESETS = ['active' => 'В работе', 'hot' => 'Горит', 'manager' => 'Ждём менеджера', 'done' => 'Закончены'];

    public const SORTS = ['deadline' => 'По сроку', 'fresh' => 'Сначала новые', 'amount' => 'По сумме'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($deal = Deal::find($key)) ? $this->detail($deal) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $stage = "(select left(md5(coalesce(b.name, s.name)), 8) from offer_positions p join workflow_stages s on s.id = p.stage_id
            left join workflow_blocks b on b.id = s.block_id where p.offer_id = deals.offer_id and p.track = 'sale' order by p.id limit 1)";
        $facets = Facets::for($request, 'crm-deals',
            Common::manager('deals.buyer_id'),
            Common::vendor('(select vendor_id from offers where offers.id = deals.offer_id)'),
            // Этап — блок маршрута по имени (как в строке): у разных вендоров одноимённые блоки — один вариант.
            Facet::column('stage', 'Этап', ['этап', 'этапа', 'этапов'], $stage)->labels(fn (array $keys) => DB::table('workflow_stages as s')
                ->leftJoin('workflow_blocks as b', 'b.id', '=', 's.block_id')
                ->selectRaw('distinct left(md5(coalesce(b.name, s.name)), 8) as k, coalesce(b.name, s.name) as name')
                ->whereRaw('left(md5(coalesce(b.name, s.name)), 8) in ('.implode(',', array_fill(0, max(count($keys), 1), '?')).')', $keys ?: [''])
                ->get()->mapWithKeys(fn ($r) => [$r->k => new Option($r->k, $r->name)])->all()),
            // Срок — шаг сделки просрочен или нет; у закрытых сделок срока нет — «в срок».
            Facet::column('due', 'Срок', ['срок', 'срока', 'сроков'], "(case when deals.state = 'active' and exists (select 1 from offer_positions p
                where p.offer_id = deals.offer_id and p.track = 'sale' and p.deadline_at < now()) then 'late' else 'ok' end)")
                ->labels(fn (array $keys) => array_intersect_key(['late' => new Option('late', 'Просрочено'), 'ok' => new Option('ok', 'В срок')], array_flip($keys))),
        );
        ListPrefs::sync($request, 'crm-deals', keep: $facets->keys());
        $preset = $request->query('preset', 'active');
        $searching = $facets->searching();
        $sort = $request->query('sort', 'deadline');

        $q = Deal::query()->with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'buyer', 'openRequirement']);
        if ($searching) {
            // Лупа — по всем сделкам: номер предложения, VIN, марка, убыток, менеджер.
            $term = trim((string) $request->query('q'));
            $like = '%'.mb_strtolower($term).'%';
            $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $o->searchCrm($term))
                ->orWhereHas('buyer', fn ($u) => $u->whereRaw('lower(name) like ?', [$like])));
        } else {
            match ($preset) {
                'hot' => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->where(fn ($w) => $w
                    ->where('deadline_at', '<', now())->orWhere(fn ($x) => $x->waiting(WaitsFor::Us)))),
                // Чей ход — позиции (`Position::waiting`): этап оплаты без счёта ждёт нас, а не менеджера.
                'manager' => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->waiting(WaitsFor::Manager)),
                'done' => $q->whereIn('state', [DealState::Done, DealState::Cancelled]),
                default => $q->where('state', DealState::Active),
            };
        }
        $facets->apply($q);
        match ($sort) {
            'fresh' => $q->latest(),
            'amount' => $q->orderByDesc('amount'),
            default => $q->orderByRaw('(select min(deadline_at) from offer_positions where offer_positions.offer_id = deals.offer_id) asc nulls last')->latest(),
        };

        return view('admin.deals.index', [
            // Таблица — вся на одной странице; строками — постранично.
            'deals' => ListView::isTable(ListView::pick($request, 0)) ? ListView::paginate($request, $q) : $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset,
            'sort' => $sort,
            'facets' => $facets,
            'detail' => $detail,
        ]);
    }

    /**
     * Сделка целиком — это редактор предложения (06.10.2026: одна страница, три дорожки — «Продажа», «Вывоз», «Сделка»
     * с деньгами, ДКП и заметкой). Старые ссылки и уведомления ведут сюда — отсюда туда.
     */
    public function show(Deal $deal)
    {
        return redirect('/offers/'.$deal->offer->number);
    }

    /** Карточка сделки рядом со списком (Detail): путь, деньги, письма, заметка — без истории. */
    private function detail(Deal $deal)
    {
        return view('admin.deals.detail', $this->data($deal));
    }

    /** Сделка целиком — одна выборка на страницу и карточку. */
    private function data(Deal $deal): array
    {
        $deal->load(['buyer', 'bid', 'requirements.media', 'requirements.stage.block', 'offer.brand', 'offer.model', 'offer.media', 'offer.settlement',
            'offer.vendor.workflows', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.positions.stage.workflow', 'offer.events.user']);
        $offer = $deal->offer;

        $threads = OfferFiles::threads($offer);

        // Письма и документы — как в редакторе: письмо вендору, документы к подписанию и ответ страховой ведут отсюда.
        return OfferFiles::letters($offer, $threads) + [
            'docs' => OfferFiles::docs($offer, $threads),
            'deal' => $deal,
            'offer' => $offer,
            'events' => $offer->events->where('created_at', '>=', $deal->created_at),
        ];
    }

    /** Деньги сделки — пока по ней нет счёта или есть только неоплаченный подбор по ДКП (`Deal::moneyEditable`). */
    public function money(Request $request, Deal $deal, UpdateDealMoney $update)
    {
        $request->merge(['owner_price' => preg_replace('/\D+/', '', (string) $request->input('owner_price')) ?: null, 'share' => preg_replace('/\D+/', '', (string) $request->input('share')) ?: null]);
        $data = $request->validate(['commission' => ['nullable', 'integer', 'min:0'], 'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)], 'owner_price' => ['nullable', 'integer', 'min:1', 'max:'.(int) ($deal->amount ?? PHP_INT_MAX)],
            'share' => ['nullable', 'integer', 'min:1']]);
        // Поле вознаграждения пустое — вознаграждения нет; поля нет вовсе (гаражная доля) — прежнее.
        $commission = $request->has('commission') ? (isset($data['commission']) ? (int) $data['commission'] : null) : $deal->commission;
        $update($deal, $request->user(), $commission, CommissionMode::tryFrom($data['mode'] ?? '') ?? $deal->commission_mode,
            DealScheme::tryFrom($data['scheme'] ?? ''), isset($data['owner_price']) ? (int) $data['owner_price'] : null, isset($data['share']) ? (int) $data['share'] : null);

        return back()->with('toast', 'Сохранено');
    }

    public function note(Request $request, Deal $deal)
    {
        $deal->update($request->validate(['notes' => ['nullable', 'string', 'max:5000']]));

        return back()->with('toast', 'Заметка сохранена');
    }
}
