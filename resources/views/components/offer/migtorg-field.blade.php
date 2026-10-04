{{-- Мигторг в поле номера убытка (`Offers\MigtorgChip`), справа внутри поля — не чип в шапке (владелец 04.10.2026: чип
     в шапке читают как статус, а не кнопку). Вписали номер — крутится кружок, пока ищем (`migtorg_controller`); лот
     нашёлся — горит знак Мигторга: нажали — данные и фото начинают заполняться; качается — кружок и «12 из 54»;
     взято — знак приглушён (если фото ещё можно докачать — снова кнопка); архив ещё обходится — серый кружок. Нет на
     Мигторге — ничего. --}}
@props(['offer', 'ref' => null])
@php
    $chip = \App\Offers\MigtorgChip::of($offer);
    $take = $chip?->action()[0] ?? null;
    $n = $offer->number;
@endphp
<span class="migtorg-field" data-controller="migtorg" data-migtorg-url-value="/offers/{{ $n }}/migtorg" data-migtorg-take-value="/offers/{{ $n }}/media/migtorg" @if ($chip?->is('running')) data-migtorg-running-value="true" @endif>
    @if ($chip?->is('running'))
        <span class="migtorg-field-state" title="Берём с Мигторга"><span class="migtorg-ring" aria-hidden="true"></span>@if ($chip->counter())<span class="nums text-xs text-ink-muted">{{ $chip->counter() }}</span>@endif</span>
    @elseif ($chip?->is('found') || ($chip?->is('done') && $take === 'take'))
        <button type="button" class="migtorg-field-btn" data-action="migtorg#take" title="{{ $chip->action()[1] }}" aria-label="{{ $chip->action()[1] }}"><x-offer.migtorg-mark/></button>
    @elseif ($chip?->is('searching'))
        {{-- Лота нет, архив Мигторга ещё обходится: кружок серый и медленный — ищет само, нажимать нечего. --}}
        <span class="migtorg-field-state" title="Ищем на Мигторге"><span class="migtorg-ring is-idle" aria-hidden="true"></span></span>
    @elseif ($chip?->is('done'))
        <span class="migtorg-field-state is-quiet" title="Взято с Мигторга"><x-offer.migtorg-mark/></span>
    @endif
    <span class="migtorg-ring migtorg-field-wait" data-migtorg-target="wait" hidden aria-hidden="true"></span>
</span>
