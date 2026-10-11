{{-- Карточка «Письма» — в сделке CRM, карточке и редакторе предложения, деле ТС и карточке заявки: все письма по машине
     строками, как дело в «Почте» (x-mail.rail), строка открывает окно писем на этом письме. Под ними одна кнопка —
     «Ответить», после нашего письма «Написать»: окно с редактором (?reply=1). Заголовок с числом ставит место. --}}
@props(['messages', 'url', 'asks' => [], 'stages' => [], 'reply' => true])
@php
    $last = collect($messages)->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->last();
    $ours = $last?->isOurs() && ! $last->isForwardedByStaff();
@endphp
@if ($last)
    <div {{ $attributes }}>
        <x-mail.rail :messages="$messages" :url="$url" :asks="$asks" :stages="$stages"/>
        @if ($reply)
            <x-mail.window-button :url="$url.(str_contains($url, '?') ? '&' : '?').'reply=1'" :label="$ours ? 'Написать' : 'Ответить'" :icon="$ours ? 'mail' : 'reply'" class="btn-s mt-1"/>
        @endif
    </div>
@endif
