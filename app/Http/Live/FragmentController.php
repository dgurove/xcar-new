<?php

namespace App\Http\Live;

use App\Live\Stream;
use App\Logistics\Transport;
use App\Park\City;
use App\Park\Vehicle;
use App\Support\CarLinks;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\Showing;
use App\Support\Nav;
use Illuminate\Http\Request;

/** Фрагменты, которые клиент перечитывает по событию из хаба. */
class FragmentController
{
    /** Пункты меню «где ещё это ТС» (`x-ui.links`): фрейм грузится, когда меню открыли. */
    public function carLinks(string $kind, int $id)
    {
        $from = match ($kind) {
            'o' => Offer::query()->findOrFail($id),
            'v' => Vehicle::withoutGlobalScope(City::SCOPE)->findOrFail($id),
            't' => Transport::query()->findOrFail($id),
        };

        return response()->view('live.car-links', ['id' => "links-{$kind}{$id}", 'links' => CarLinks::for($from)])
            ->header('Cache-Control', 'no-store');
    }

    public function badges(Request $request)
    {
        return Stream::view('live.badges-stream', ['badges' => Nav::badges($request->user())]);
    }

    /**
     * Плитки пачкой (18.11.2026): клиент копит события `card` и спрашивает разом — один запрос на каталог вместо запроса
     * на машину. Публикация пачкой из N машин при M открытых каталогах была N×M запросов в одну секунду, каждый со
     * всей оболочкой запроса (сессия, счётчики) — теперь M. `n` — номера через запятую, `present` — те, что уже на экране.
     */
    public function cards(Request $request)
    {
        $user = $request->user();
        $numbers = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('n')))))), 0, 60);
        $present = array_flip(array_map('intval', explode(',', (string) $request->query('present'))));
        $inGallery = $request->query('list') === 'gallery';
        $found = Offer::whereIn('number', $numbers)->get();
        // Та же выборка, что у каталога («новое» от открытия этому человеку, «Ваша цена», свой интерес), — на всю пачку.
        $cards = collect();
        foreach ($found->groupBy(fn (Offer $o) => $o->isGallery() ? 1 : 0) as $gallery => $group) {
            $cards = $cards->merge(CatalogQuery::cards($user, (bool) $gallery)->whereKey($group->modelKeys())->get());
        }
        $cards = $cards->keyBy('id');
        Showing::remember($user, $found->modelKeys());
        $seen = array_flip(Offer::visibleTo($user)->whereKey($found->modelKeys())->pluck('id')->all());

        return Stream::view('site.offers.cards-stream', ['cards' => $found->map(function (Offer $offer) use ($cards, $seen, $present, $inGallery) {
            $card = $cards->get($offer->id) ?? $offer;

            return ['offer' => $card, 'present' => isset($present[$offer->number]),
                'visible' => ($inGallery ? $card->isGallery() : $card->state->isPublic()) && isset($seen[$offer->id])];
        })]);
    }

    public function card(Request $request, Offer $offer)
    {
        // Та же выборка, что у каталога: «новое» от открытия этому человеку, «Ваша цена», свой интерес.
        $offer = CatalogQuery::cards($request->user(), $offer->isGallery())->whereKey($offer->id)->first() ?? $offer;
        Showing::remember($request->user(), [$offer->id]);
        // Карточка живёт в ленте, только если человеку положено её видеть: чужому — remove.
        $visible = ($request->query('list') === 'gallery' ? $offer->isGallery() : $offer->state->isPublic())
            && $offer->isVisibleTo($request->user());
        $present = $request->boolean('present');

        return Stream::view('site.offers.card-stream', ['offer' => $offer, 'visible' => $visible, 'present' => $present]);
    }
}
