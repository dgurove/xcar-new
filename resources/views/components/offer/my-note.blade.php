{{-- Личная заметка к ТС на xcar.ru (18.10.2026, владелец: «менеджеры очень просят»): у каждого своя, видна только ему.
     Сохраняется сама — через секунду тишины, при уходе с поля и со страницы (`note_controller`), кнопки нет. Под «Войти
     как» — только чтение: заметка того, за кого вошли. Менеджеру, покупателю и админу (`User::keepsNotes`). bare — тоном
     поля, а не коробки (карточка строки каталога: коробка в карточке была бы плашкой в плашке). --}}
@props(['offer', 'bare' => false])
@php
    $user = auth()->user();
    $text = \App\Offers\OfferNote::of($user, $offer);
    $readonly = \App\Users\Impersonation::active();
@endphp
@if ($user?->keepsNotes() && ($text !== '' || ! $readonly))
    {{-- Одна плашка: значок и поле, без заголовка и плашки в плашке (19.10.2026, владелец: «улучши вёрстку на телефонах»). --}}
    <label {{ $attributes->class(['note-card', 'note-card--bare' => $bare]) }}>
        <x-ui.icon name="edit" class="note-card-icon size-[18px]"/>
        <textarea class="note-field" rows="1" maxlength="{{ \App\Offers\OfferNote::MAX }}" aria-label="Заметка"
            placeholder="Добавьте свою заметку по этому ТС. Она будет видна только Вам."
            @if ($readonly) readonly @else data-controller="note" data-note-url-value="/offers/{{ $offer->number }}/note" data-action="input->note#changed blur->note#flush" @endif>{{ $text }}</textarea>
    </label>
@endif
