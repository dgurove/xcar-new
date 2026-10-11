{{-- «Отдать менеджеру на оценку» (11.10.2026): менеджеры строками с галкой, поиск от девяти, кому уже отдано — отмечено и
     не нажимается; «Отдать (N)» внизу. Кнопка шторки стоит под ценами в окошке «Оценить». --}}
@props(['offer', 'managers'])
@php
    $n = $offer->number;
    $asked = $offer->openEstimates->pluck('user_id')->all();
@endphp
<div data-controller="sheet" class="contents">
    <button type="button" class="btn btn-s btn-quiet self-start" data-action="sheet#open"><x-ui.icon name="users" class="size-4"/> Отдать менеджеру на оценку</button>
    <x-ui.sheet id="estimate-ask-{{ $n }}" title="Отдать на оценку">
        <form method="post" action="/offers/{{ $n }}/estimates" class="flex flex-col gap-4" data-controller="select">
            @csrf
            @if ($managers->count() > 8)<input type="search" class="field-input field-s" placeholder="Найти" autocomplete="off" data-action="input->select#filter">@endif
            <div class="list">
                @foreach ($managers as $m)
                    @php $on = in_array($m->id, $asked, true); @endphp
                    {{-- Уже оценивает — отмечен и не нажимается; без имени поля: `disabled` здесь снимает reveal панели «Оценить». --}}
                    <label @class(['row row-check', 'pointer-events-none opacity-60' => $on]) data-select-target="item" data-name="{{ mb_strtolower($m->name) }}">
                        <x-ui.avatar :user="$m" :size="40"/>
                        <span class="min-w-0 flex-1 truncate">{{ $m->name }}</span>
                        <span class="check">@if ($on)<input type="checkbox" checked tabindex="-1" aria-disabled="true">@else<input type="checkbox" name="managers[]" value="{{ $m->id }}" data-select-target="box" data-action="select#count">@endif</span>
                    </label>
                @endforeach
            </div>
            <div class="sheet-foot"><button type="submit" class="btn btn-accent w-full" data-select-target="submit" disabled>Отдать <span class="nums" data-select-target="count"></span></button></div>
        </form>
    </x-ui.sheet>
</div>
