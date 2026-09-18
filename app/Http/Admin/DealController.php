<?php

namespace App\Http\Admin;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Workflow\WaitsFor;
use Illuminate\Http\Request;

class DealController
{
    public const PRESETS = ['active' => 'В работе', 'hot' => 'Горит', 'manager' => 'Ждём менеджера', 'done' => 'Закончены'];

    public const SORTS = ['deadline' => 'По сроку', 'fresh' => 'Сначала новые', 'amount' => 'По сумме'];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'crm-deals');
        $preset = $request->query('preset', 'active');
        $sort = $request->query('sort', 'deadline');

        $q = Deal::query()->with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'buyer', 'openRequirement']);
        match ($preset) {
            'hot' => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->where(fn ($w) => $w
                ->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))),
            'manager' => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->whereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Manager))),
            'done' => $q->whereIn('state', [DealState::Done, DealState::Cancelled]),
            default => $q->where('state', DealState::Active),
        };
        match ($sort) {
            'fresh' => $q->latest(),
            'amount' => $q->orderByDesc('amount'),
            default => $q->orderByRaw('(select min(deadline_at) from offer_positions where offer_positions.offer_id = deals.offer_id) asc nulls last')->latest(),
        };

        return view('admin.deals.index', [
            'deals' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset,
            'sort' => $sort,
        ]);
    }

    /** Сделка целиком: маршрут с исходами, просьбы менеджеру и ответы, машина и покупатель, история. */
    public function show(Deal $deal)
    {
        $deal->load(['buyer', 'bid', 'requirements.media', 'requirements.stage.block', 'offer.brand', 'offer.model', 'offer.media', 'offer.settlement',
            'offer.vendor.workflows', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.positions.stage.workflow', 'offer.events.user']);
        $offer = $deal->offer;

        return view('admin.deals.show', [
            'deal' => $deal,
            'offer' => $offer,
            'stages' => $offer->vendor ? $offer->vendor->workflows->mapWithKeys(fn ($w) => [$w->track->label() => $w->stages()->with('block')->get()->mapWithKeys(fn ($s) => [$s->id => $s->block->name.' › '.$s->name])]) : collect(),
            'events' => $offer->events->where('created_at', '>=', $deal->created_at),
            'dealCard' => false,
        ]);
    }

    public function note(Request $request, Deal $deal)
    {
        $deal->update($request->validate(['notes' => ['nullable', 'string', 'max:5000']]));

        return back()->with('toast', 'Заметка сохранена');
    }
}
