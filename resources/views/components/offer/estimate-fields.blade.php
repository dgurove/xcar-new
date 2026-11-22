{{-- Поля «Отдать на оценку» — одни у окна машины (`x-offer.estimate-ask`) и у пачки из «Без продажной цены»
     (`x-offer.estimate-pack`, 22.11.2026): менеджеры строками с галкой, поиск от девяти, под именем «на оценке N ТС»; кому уже
     отдано (`asked`) — отмечен и не нажимается; отметил — под ним «Заявленная: Показать | Скрыть» без умолчания (`declared` —
     сумма у одной машины, `true` — у пачки: ответ на все её машины с заявленной); внизу сроки и кнопка. Эксклюзив
     (`exclusive`) — строкой, когда отмечен один (`select_controller`). `pick` — менеджер, отмеченный сразу (грузовое).
     Форма — у того, кто вставляет; `select_controller` — на ней. --}}
@props(['managers', 'asked' => [], 'pick' => null, 'declared' => null, 'exclusive' => false, 'submit' => 'Отдать'])
@php
    // Сколько у человека уже открытых просьб — чтобы не завалить одного (подписью под именем).
    $busy = \App\Offers\Estimate::waiting()->whereIn('user_id', $managers->pluck('id'))->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id')->all();
@endphp
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
            <span class="check">@if ($on)<input type="checkbox" checked tabindex="-1" aria-disabled="true">@else<input type="checkbox" name="managers[]" value="{{ $m->id }}" data-select-target="box" data-action="select#count" @checked($m->id === $pick)>@endif</span>
        </label>
        @if ($declared && ! $on)
            <div class="row estimate-declared" data-select-ask="{{ $m->id }}" hidden>
                <span class="min-w-0 flex-1 text-sm text-ink-muted nums">{{ $declared === true ? 'Заявленная' : 'Заявленная '.\App\Support\Money::rub($declared) }}</span>
                <span class="segment shrink-0">
                    <label><input type="radio" name="declared[{{ $m->id }}]" value="1" disabled data-action="select#count">Показать</label>
                    <label><input type="radio" name="declared[{{ $m->id }}]" value="0" disabled data-action="select#count">Скрыть</label>
                </span>
            </div>
        @endif
        </div>
    @endforeach
</div>
{{-- Сроки — у кнопки, а не под списком из трёх десятков менеджеров: видны сразу. --}}
<div class="sheet-foot flex flex-col gap-2">
    <div class="estimate-term">
        <span>На оценку</span>
        <div class="segment">
            @foreach (\App\Offers\Estimate::DAYS as $d)
                <label><input type="radio" name="days" value="{{ $d }}" @checked($d === 2)>{{ \App\Offers\Estimate::daysWord($d) }}</label>
            @endforeach
        </div>
    </div>
    @if ($exclusive)
        {{-- Эксклюзив — когда оценивает один: при двух и больше (и когда уже кто-то думает) строки нет, решает админ «Взять». --}}
        <div class="estimate-term" data-select-single hidden>
            <span>Эксклюзив</span>
            <div class="segment">
                @foreach (\App\Offers\Estimate::EXCLUSIVE as $d)
                    <label><input type="radio" name="exclusive" value="{{ $d }}" disabled @checked($d === ($pick ? 5 : 0))>{{ $d ? \App\Offers\Estimate::daysWord($d) : 'Нет' }}</label>
                @endforeach
            </div>
        </div>
    @endif
    <button type="submit" class="btn btn-accent mt-1 w-full" data-select-target="submit" disabled>{{ $submit }} <span class="nums" data-select-target="count"></span></button>
</div>
