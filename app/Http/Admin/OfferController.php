<?php

namespace App\Http\Admin;

use App\Cars\Settlement;
use App\Chats\Chat;
use App\Chats\Message as ChatMessage;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Thread;
use App\Media\Actions\WarmPhotos;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Tag;
use App\Support\ListPrefs;
use App\Workflow\Insurer;
use Illuminate\Http\Request;

class OfferController
{
    public const PRESETS = [
        'all' => 'Все', 'draft' => 'Черновики', 'open' => 'В продаже',
        'bids' => 'Ждут ответа', 'sold' => 'В сделке', 'archive' => 'Архив',
    ];

    public const SORTS = ['fresh' => 'Сначала новые', 'bids' => 'По подтверждениям', 'closing' => 'Скоро закроются', 'number' => 'По номеру'];

    public function index(Request $request)
    {
        $preset = $request->query('preset', 'all');
        ListPrefs::sync($request, 'crm-offers');
        $sort = $request->query('sort', 'fresh');

        $q = Offer::query()->with(['brand', 'model', 'media'])->withCount(['activeBids', 'interests'])->withMax('activeBids as top_bid', 'amount');

        match ($preset) {
            'draft' => $q->where('state', OfferState::Draft),
            'open' => $q->where('state', OfferState::Open),
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
            'bids' => $q->orderByDesc('active_bids_count')->orderByRaw('top_bid desc nulls last')->orderByDesc('updated_at'),
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
            'chats' => Chat::with('user')->where('offer_id', $offer->id)->addSelect(['*', 'last_text' => ChatMessage::select('text')->whereColumn('chat_id', 'chats.id')->orderByDesc('seq')->limit(1)])->orderByDesc('last_message_at')->get(),
            'import' => ImportThreadFiles::progress($offer->id),
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

    /** Продлить приём на ходу: от текущего срока, если он ещё не прошёл, иначе от сейчас. */
    public function extend(Request $request, Offer $offer, UpdateOffer $update)
    {
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'in:15,60']])['minutes'];
        $from = $offer->bids_close_at?->isFuture() ? $offer->bids_close_at : now();
        $offer = $update($offer, ['bids_close_at' => $from->copy()->addMinutes($minutes)], $request->user());

        return back()->with('toast', 'Приём до '.$offer->bids_close_at->translatedFormat('j M, H:i'));
    }

    public function state(Request $request, Offer $offer, ChangeOfferState $change)
    {
        $next = OfferState::from($request->validate(['state' => ['required', 'string']])['state']);
        $change($offer, $next, $request->user());

        return redirect("/predlozheniya/{$offer->number}")->with('toast', $next->label());
    }
}
