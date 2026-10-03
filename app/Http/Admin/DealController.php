<?php

namespace App\Http\Admin;

use App\Offers\Actions\UpdateDealMoney;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\OfferFiles;
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
            $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $o->search($term)->orWhereRaw('lower(claim_ref) like ?', [$like]))
                ->orWhereHas('buyer', fn ($u) => $u->whereRaw('lower(name) like ?', [$like])));
        } else {
            match ($preset) {
                'hot' => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->where(fn ($w) => $w
                    ->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))),
                'manager' => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->whereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Manager))),
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
            'deals' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset,
            'sort' => $sort,
            'facets' => $facets,
        ]);
    }

    /** Сделка целиком: маршрут с исходами, деньги, письма и документы, просьбы менеджеру и ответы, машина и покупатель, история. */
    public function show(Deal $deal)
    {
        $deal->load(['buyer', 'bid', 'requirements.media', 'requirements.stage.block', 'offer.brand', 'offer.model', 'offer.media', 'offer.settlement',
            'offer.vendor.workflows', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.positions.stage.workflow', 'offer.events.user']);
        $offer = $deal->offer;

        $threads = OfferFiles::threads($offer);

        // Письма и документы — как в редакторе: письмо вендору, документы к подписанию и ответ страховой ведут отсюда.
        return view('admin.deals.show', OfferFiles::letters($offer, $threads) + [
            'docs' => OfferFiles::docs($offer, $threads),
            'deal' => $deal,
            'offer' => $offer,
            'events' => $offer->events->where('created_at', '>=', $deal->created_at),
        ]);
    }

    /** Вознаграждение и режим — пока по сделке нет счёта. */
    public function money(Request $request, Deal $deal, UpdateDealMoney $update)
    {
        $data = $request->validate(['commission' => ['nullable', 'integer', 'min:0'], 'mode' => ['required', Rule::enum(CommissionMode::class)]]);
        $update($deal, $request->user(), isset($data['commission']) ? (int) $data['commission'] : null, CommissionMode::from($data['mode']));

        return back()->with('toast', 'Сохранено');
    }

    public function note(Request $request, Deal $deal)
    {
        $deal->update($request->validate(['notes' => ['nullable', 'string', 'max:5000']]));

        return back()->with('toast', 'Заметка сохранена');
    }
}
