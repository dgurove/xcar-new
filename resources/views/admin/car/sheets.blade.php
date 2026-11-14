{{-- Шторки действий машины — одни у окна и страницы: их открывают пункты меню (`admin.car.actions`) и кнопки плашек
     событием «имя:open». Шторки гаража — в карточке «Гараж» (`garage.cars.sheets`). --}}
@if ($resale || ($admin && $offer->onAuction()))<x-offer.resale-sheet :offer="$offer"/>@endif
@if ($resale && $offer->state === \App\Offers\OfferState::Sold && $offer->deal?->isActive())<x-deal.declined-sheet :deal="$offer->deal"/>@endif
@if ($resale && \App\Offers\Actions\MarkSoldDeal::allows($offer->deal))<x-deal.sold-sheet :deal="$offer->deal"/>@endif
@if ($admin && ($closedDeal = $offer->lastDeal) && \App\Offers\Actions\ReopenDeal::allows($closedDeal, auth()->user()))<x-deal.reopen-sheet :deal="$closedDeal"/>@endif
@if ($car->sellsAtAuction())<x-offer.auction-sale-sheet :offer="$offer"/>@endif
@if ($publishItems && ! $publishButton)<x-offer.urgent-sheet :offer="$offer"/><x-offer.day-sheet :offer="$offer"/>@endif
@if ($askMenu && $managers->isNotEmpty())<x-offer.estimate-ask :offer="$offer" :managers="$managers" :trigger="false"/>@endif
@if ($giveMenu)<x-offer.give-sheet :offer="$offer"/>@endif
@if ($admin && \App\Offers\Actions\PublishDeal::allows($offer))<x-offer.deal-publish-sheet :offer="$offer"/>@endif
@if ($publishButton)@include('admin.offers.publish-sheet-slot')@endif
