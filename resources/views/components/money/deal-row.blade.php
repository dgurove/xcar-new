{{-- Сделка-расчёт строкой: фото, ТС, одна фраза о деньгах и № предложения; справа — число, которое сейчас важно.
     href — куда ведёт (кабинет: расчёт, CRM: сделка). person — показать менеджера (CRM). --}}
@props(['deal', 'href', 'person' => false])
@php use App\Support\Money; $offer = $deal->offer; $m = $deal->money ?? \App\Billing\DealMoney::of($deal); @endphp
<a href="{{ $href }}" class="row items-start">
    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="mt-0.5 block text-sm {{ $m->phraseClass() }}">{{ $m->phrase }}</span>
        <span class="row-sub"><span class="tag nums">№ {{ $offer->number }}</span>@if ($person && $deal->buyer)<x-ui.person :user="$deal->buyer"/>@endif</span>
    </span>
    @if ($m->amount !== null)<span class="nums shrink-0 {{ $m->amountClass() }}">{{ Money::rub($m->amount) }}</span>@endif
</a>
