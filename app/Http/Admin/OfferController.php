<?php

namespace App\Http\Admin;

use App\Cars\Settlement;
use App\Mail\Jobs\ImportCandidateMedia;
use App\Mail\Thread;
use App\Media\Actions\WarmPhotos;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Tag;
use App\Workflow\Insurer;
use Illuminate\Http\Request;

class OfferController
{
    public const PRESETS = [
        'all' => 'Все', 'draft' => 'Черновики', 'open' => 'В продаже',
        'bids' => 'С подтверждениями', 'sold' => 'В сделке', 'archive' => 'Архив',
    ];

    public const SORTS = ['fresh' => 'Сначала новые', 'closing' => 'Скоро закроются', 'number' => 'По номеру'];

    public function index(Request $request)
    {
        $preset = $request->query('preset', 'all');
        $sort = $request->query('sort', 'fresh');

        $q = Offer::query()->with(['brand', 'model', 'media'])->withCount(['activeBids', 'interests']);

        match ($preset) {
            'draft' => $q->where('state', OfferState::Draft),
            'open' => $q->whereIn('state', [OfferState::Open, OfferState::Closed]),
            'bids' => $q->whereHas('bids', fn ($b) => $b->where('state', BidState::Active)),
            'sold' => $q->whereIn('state', [OfferState::Sold, OfferState::Delivered]),
            'archive' => $q->whereIn('state', [OfferState::Archived, OfferState::Cancelled]),
            // Галерея — свой раздел.
            default => $q->whereNotIn('state', [OfferState::Archived, OfferState::Gallery]),
        };
        if ($term = trim((string) $request->query('q'))) {
            $q->search($term);
        }
        match ($sort) {
            'closing' => $q->orderByRaw('bids_close_at asc nulls last'),
            'number' => $q->orderByDesc('number'),
            default => $q->orderByDesc('updated_at'),
        };

        return view('admin.offers.index', [
            'offers' => $q->paginate(24)->withQueryString(),
            'preset' => $preset,
            'sort' => $sort,
            'counts' => [
                'draft' => Offer::where('state', OfferState::Draft)->count(),
                'bids' => Offer::whereHas('bids', fn ($b) => $b->where('state', BidState::Active))->count(),
            ],
        ]);
    }

    public function store(Request $request, CreateOffer $create)
    {
        $offer = $create($request->user());

        return redirect("/predlozheniya/{$offer->number}");
    }

    public function edit(Offer $offer)
    {
        $offer->load(['brand', 'model', 'settlement', 'media', 'bids.user', 'interests.user', 'events.user', 'insurer.workflows',
            'positions.stage.block', 'positions.stage.exits.to', 'positions.stage.workflow', 'deal.buyer']);
        // Давно закрытое предложение лежит в холодном слое без конверсий — досчитать, раз открыли.
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)) {
            app(WarmPhotos::class)($offer);
        }

        return view('admin.offers.edit', [
            'offer' => $offer,
            'threads' => Thread::where('offer_id', $offer->id)->get(),
            'import' => ImportCandidateMedia::progress($offer->id),
            'insurers' => Insurer::where('is_active', true)->orWhere('id', $offer->insurer_id)->orderBy('name')->pluck('name', 'id'),
            'stages' => $offer->insurer ? $offer->insurer->workflows->mapWithKeys(fn ($w) => [$w->track->label() => $w->stages()->with('block')->get()->mapWithKeys(fn ($s) => [$s->id => $s->block->name.' › '.$s->name])]) : collect(),
            'tags' => Tag::orderBy('sort')->get(),
            'settlements' => Settlement::orderByDesc('is_federal_city')->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(OfferRequest $request, Offer $offer, UpdateOffer $update)
    {
        $update($offer, $request->payload(), $request->user());

        return redirect("/predlozheniya/{$offer->number}")->with('toast', 'Сохранено');
    }

    public function state(Request $request, Offer $offer, ChangeOfferState $change)
    {
        $next = OfferState::from($request->validate(['state' => ['required', 'string']])['state']);
        $change($offer, $next, $request->user());

        return redirect("/predlozheniya/{$offer->number}")->with('toast', $next->label());
    }
}
