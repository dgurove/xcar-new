<?php

namespace App\Http\Live;

use App\Live\Stream;
use App\Logistics\Transport;
use App\Offers\Binding;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\Showing;
use App\Support\Nav;
use App\Support\Surface;
use Illuminate\Http\Request;

/** Фрагменты, которые клиент перечитывает по событию из хаба. */
class FragmentController
{
    public function badges(Request $request)
    {
        return Stream::view('live.badges-stream', ['badges' => Nav::badges($request->user())]);
    }

    /**
     * Шторка тревоги «нет покупателя» (`x-ui.header-btn name="alarm"`): машины, где горит или недавно вышел срок
     * обязывающего, ближние сверху. В логистике строка ведёт в задачу вывоза, в CRM — в предложение.
     */
    public function alarms(Request $request)
    {
        $user = $request->user();
        abort_unless(Binding::alarmsFor($user), 404);
        $offers = Binding::alarms($user);
        $logistics = Surface::current() === Surface::Logistics;
        $tasks = $logistics ? Transport::open()->whereIn('car_id', $offers->pluck('car_id')->filter())->pluck('id', 'car_id') : collect();

        return view('live.alarms', [
            'frame' => (string) $request->header('Turbo-Frame', 'alarms'),
            'burning' => $offers->filter(fn (Offer $o) => $o->binding_until->isFuture()),
            'over' => $offers->filter(fn (Offer $o) => $o->binding_until->isPast()),
            'href' => fn (Offer $o) => $logistics
                ? (isset($tasks[$o->car_id]) ? '/transports/'.$tasks[$o->car_id] : ($user->leadsLogistics() ? Surface::Crm->url('/offers/'.$o->number) : null))
                : '/offers/'.$o->number,
        ]);
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
