{{-- «Опубликовать для других менеджеров» (12.11.2026, владелец: «она в сделке у этого менеджера, но начальник хочет
     опубликовать её для других менеджеров»): машина остаётся в сделке, остальным менеджерам — обычное новое предложение до
     конца приёма, их подтверждения — резервом («Отдать» в «Подтверждениях»). `ShowDealToAll`, `POST /offers/{n}/show-all`;
     открывает пункт «⋯» окна и «···» страницы событием show-all:open. --}}
@props(['offer'])
@php
    $n = $offer->number;
    $until = old('until', \App\Offers\Binding::clampClose($offer, \App\Offers\Slots::closeFor(now()))->format('Y-m-d\TH:i'));
@endphp
<div data-controller="sheet" data-action="show-all:open@window->sheet#open" class="contents">
    <x-ui.sheet id="show-all-{{ $n }}" title="Опубликовать для других менеджеров">
        <form method="post" action="/offers/{{ $n }}/show-all" class="flex flex-col gap-4">
            @csrf
            <div class="fields">
                <x-ui.field name="until" :id="'show-all-until-'.$n" label="Приём подтверждений до" type="datetime-local" default-time="17:30" :value="$until" :limit="\App\Offers\Binding::closeLimit($offer)" required/>
            </div>
            <x-ui.sheet-foot>Опубликовать</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
