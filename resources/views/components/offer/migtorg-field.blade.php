{{-- Мигторг в поле номера убытка (`Offers\MigtorgChip`), справа внутри поля — не чип в шапке (владелец 04.10.2026: чип
     в шапке читают как статус, а не кнопку). Вписали номер — крутится кружок, пока ищем (`lot_field_controller`); лот
     нашёлся — горит знак Мигторга: нажали — данные и фото начинают заполняться; качается — кружок и «12 из 54»;
     взято — знак приглушён (если фото ещё можно докачать — снова кнопка); архив ещё обходится — серый кружок. Нет на
     Мигторге — ничего. --}}
@props(['offer', 'ref' => null])
@php
    $chip = \App\Offers\MigtorgChip::of($offer);
    $take = $chip?->action()[0] ?? null;
    $n = $offer->number;
@endphp
<span class="lot-field" data-controller="lot-field" data-lot-field-url-value="/offers/{{ $n }}/migtorg" data-lot-field-take-value="/offers/{{ $n }}/media/migtorg" @if ($chip?->is('running')) data-lot-field-running-value="true" @endif>
    @if ($chip?->is('running'))
        <span class="lot-field-state" title="Берём с Мигторга"><span class="lot-ring" aria-hidden="true"></span>@if ($chip->counter())<span class="nums text-xs text-ink-muted">{{ $chip->counter() }}</span>@endif</span>
    @elseif ($chip?->is('found') || ($chip?->is('done') && $take === 'take'))
        <button type="button" class="lot-field-btn" data-action="lot-field#take" title="{{ $chip->action()[1] }}" aria-label="{{ $chip->action()[1] }}"><x-offer.migtorg-mark/></button>
    @elseif ($chip?->is('searching'))
        {{-- Лота нет, архив Мигторга ещё обходится: кружок серый и медленный — ищет само, нажимать нечего. --}}
        <span class="lot-field-state" title="Ищем на Мигторге"><span class="lot-ring is-idle" aria-hidden="true"></span></span>
    @elseif ($chip?->is('done'))
        <span class="lot-field-state is-quiet" title="Взято с Мигторга"><x-offer.migtorg-mark/></span>
    @endif
    <span class="lot-ring lot-field-wait" data-lot-field-target="wait" hidden aria-hidden="true"></span>
</span>
