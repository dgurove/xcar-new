{{-- «Отдать менеджеру на оценку» (11.10.2026): менеджеры строками с галкой, поиск от девяти, кому уже отдано — отмечено и
     не нажимается; отметил — под ним «Заявленная N ₽: Показать | Скрыть» без умолчания (владелец: «пусть просто
     спрашивает»), «Отдать (N)» живая, когда ответили за всех; внизу. Кнопка шторки — строкой в группе оценок; trigger=false — только шторка, её открывает
     событие `estimate-ask-{n}:open` (пункт «Спросить менеджеров» в «Действиях» карточки «Оцененных»). --}}
@props(['offer', 'managers', 'label' => 'Отдать на оценку', 'trigger' => true])
@php
    $n = $offer->number;
    $asked = $offer->openEstimates->pluck('user_id')->all();
    // Сколько у человека уже открытых просьб — чтобы не завалить одного (подписью под именем).
    $declared = $offer->declaredPrice();
    $busy = \App\Offers\Estimate::waiting()->whereIn('user_id', $managers->pluck('id'))->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id')->all();
@endphp
<div data-controller="sheet" class="contents" data-action="estimate-ask-{{ $n }}:open@window->sheet#open">
    @if ($trigger)<button type="button" class="row w-full text-left text-accent-text" data-action="sheet#open"><x-ui.row-icon name="plus" tone="accent" size="s"/><span class="min-w-0 flex-1">{{ $label }}</span></button>@endif
    <x-ui.sheet id="estimate-ask-{{ $n }}" :title="$offer->asking_price ? 'Спросить менеджеров' : 'Отдать на оценку'">
        <form method="post" action="/offers/{{ $n }}/estimates" class="flex flex-col gap-4" data-controller="select">
            @csrf
            @if ($managers->count() > 8)<input type="search" class="field-input field-s" placeholder="Найти" autocomplete="off" data-action="input->select#filter">@endif
            <div class="list">
                @foreach ($managers as $m)
                    @php $on = in_array($m->id, $asked, true); @endphp
                    {{-- Уже оценивает — отмечен и не нажимается; без имени поля: `disabled` здесь снимает reveal панели «Оценить». --}}
                    <div class="contents" data-select-target="item" data-name="{{ mb_strtolower($m->name) }}">
                    <label @class(['row row-check', 'pointer-events-none opacity-60' => $on])>
                        <x-ui.avatar :user="$m" :size="40"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $m->name }}</span>
                            @if ($on)<span class="row-sub">уже спросили</span>@elseif ($busy[$m->id] ?? 0)<span class="row-sub nums">на оценке {{ $busy[$m->id] }} ТС</span>@endif
                        </span>
                        <span class="check">@if ($on)<input type="checkbox" checked tabindex="-1" aria-disabled="true">@else<input type="checkbox" name="managers[]" value="{{ $m->id }}" data-select-target="box" data-action="select#count">@endif</span>
                    </label>
                    @if ($declared && ! $on)
                        <div class="row estimate-declared" data-select-ask="{{ $m->id }}" hidden>
                            <span class="min-w-0 flex-1 text-sm text-ink-muted nums">Заявленная {{ \App\Support\Money::rub($declared) }}</span>
                            <span class="segment shrink-0">
                                <label><input type="radio" name="declared[{{ $m->id }}]" value="1" disabled data-action="select#count">Показать</label>
                                <label><input type="radio" name="declared[{{ $m->id }}]" value="0" disabled data-action="select#count">Скрыть</label>
                            </span>
                        </div>
                    @endif
                    </div>
                @endforeach
            </div>
            <div class="sheet-foot"><button type="submit" class="btn btn-accent w-full" data-select-target="submit" disabled>{{ $offer->asking_price ? 'Спросить' : 'Отдать' }} <span class="nums" data-select-target="count"></span></button></div>
        </form>
    </x-ui.sheet>
</div>
