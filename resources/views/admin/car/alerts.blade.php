{{-- Что ждёт решения по машине — плашками верхом окна и страницы, одним условием доступа: черновик из «Заявок на приём»
     (`AutoSale`) — «Принять» / «Отклонить»; переоценка закупочной (28.10.2026); вопросы менеджерам про закупочную; письмо
     страховой на разбор — у сделки без пути (с путём оно строкой в текущем шаге, `x-route.path`). --}}
@if ($admin && $offer->proposed())<x-offer.proposal :offer="$offer" class="w-full"/>@endif
@if ($admin && ($reprice = $offer->pendingReprice))<x-offer.reprice :offer="$offer" :reprice="$reprice" class="w-full" :sheet-only="\App\Offers\RepriceFlow::inRoute($offer)"/>@endif
<x-offer.reprice-asks :offer="$offer" class="w-full"/>
@if ($admin && $offer->deal && ! $offer->position(\App\Workflow\Track::Sale))<x-deal.reply-review :deal="$offer->deal" class="w-full"/>@endif
