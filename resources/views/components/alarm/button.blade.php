{{-- Кнопка тревоги шапки (`x-ui.header-btn name="alarm"`): число машин без покупателя с горящим сроком; нет их — кнопка
     скрыта. Её одну подменяет `/live/badges` (`data-alarm-button`), шторка рядом остаётся открытой. --}}
@props(['wide' => false])
@php $alarms = \App\Offers\Binding::alarmCount(auth()->user()); @endphp
<button type="button" class="{{ $wide ? 'header-btn header-h px-3' : 'header-btn header-sq' }} alarm-btn" data-alarm-button="{{ $wide ? 'wide' : 'phone' }}" data-action="sheet#open alarms#open"
    aria-label="Нет покупателя: {{ $alarms }}" title="Нет покупателя, горит срок обязывающего" @unless ($alarms) hidden @endunless>
    <x-ui.icon name="siren" class="{{ $wide ? 'size-4' : 'size-[18px]' }}"/><span class="nums">{{ $alarms }}</span>
</button>
