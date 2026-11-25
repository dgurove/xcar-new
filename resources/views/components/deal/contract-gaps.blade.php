{{-- Чего договору не хватает с нашей стороны — строками «Продажи» (16.11.2026, владелец: «„Внести ПТС для договора“ и „Заполнить данные
     продавца“ в ходе сделки — как будто ждём это от менеджера»; «не продавец, а владелец»). Один источник — `DealContract::missing()`;
     покупатель — строкой `x-deal.buyer`, цена у ПРАЙМ и Р.О.С. — в шапке «Продажи» (`x-deal.price-money`). --}}
@props(['deal', 'priceRow' => false])
@php
    $missing = $deal->isActive() && $deal->hasContract() ? \App\Offers\DealContract::for($deal)->missing() : [];
    $page = '/offers/'.$deal->offer->number;
@endphp
{{-- Собственник по договору комиссии — своей строкой «Собственник» (`x-deal.owner`, 25.11.2026). --}}
@if (in_array('данные продавца', $missing, true) || (in_array('данные собственника', $missing, true) && ! \App\Offers\Actions\SetDealOwner::allows($deal)))
    <x-deal.sale-row label="Владелец" value="нет данных" tone="danger" :href="$page.'?dkp=1#dkp'"/>
@endif
@if (in_array('(Э)ПТС', $missing, true))
    <x-deal.sale-row label="ПТС" value="не внесён" tone="danger" :href="$page.'#car-docs'"/>
@endif
@if (in_array('цена', $missing, true) && ! $priceRow)
    <x-deal.sale-row label="Сумма договора" value="не указана" tone="danger" :href="$page.'?dkp=1#dkp'"/>
@endif
