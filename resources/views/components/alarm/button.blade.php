{{-- Кнопка тревоги шапки (`x-ui.header-btn name="alarm"`): число горящих машин; нет их — кнопка скрыта. Ведёт на экран
     «Горит» (`/alarms`). Её одну подменяет `/live/badges` (`data-alarm-button`). --}}
@props(['wide' => false])
@php $alarms = \App\Offers\Binding::alarmCount(auth()->user()); @endphp
<a href="/alarms" class="{{ $wide ? 'header-btn header-btn-square header-h w-[1.875rem]' : 'header-btn header-sq' }} relative alarm-btn" data-alarm-button="{{ $wide ? 'wide' : 'phone' }}"
    aria-label="Горит срок обязывающего: {{ $alarms }}" title="Горит срок обязывающего: нет покупателя или поставщику не заплачено" @unless ($alarms) hidden @endunless>
    {{-- Число — кружком в углу, как у колокольчика и чатов (19.10.2026, владелец: внутри кнопки «раздвигает вёрстку»). --}}
    <x-ui.icon name="siren" class="{{ $wide ? 'size-4' : 'size-[18px]' }}"/><span class="badge alarm-badge">{{ $alarms > 99 ? '99+' : $alarms }}</span>
</a>
