{{-- Заявка в плитках и строках: карточка ТС целиком нажимается. Две строки: название с госномером и строка
     текста — номер убытка; третья — откуда заявка. Справа — тип заявки. Пересказа под названием нет: «Выдать, реализовано 18 сен» — это то же, что тип
     справа, а из-за лишней строки на экран влезало вдвое меньше заявок. Кнопок нет. --}}
@props(['req', 'arrive' => null])
@php
    // Заявку завела почта, документы ещё читаются — силуэт плитки (как строка таблицы, `x-park.request-row`).
    $state = $arrive['state'] ?? null;
    $busy = in_array($state, ['ghost', 'reading'], true);
    $tone = $req->isOpen() ? 'soft' : 'closed';
    $v = $req->vehicle;
    // У закрытой словом её исход, у открытой — тип.
    $word = $req->isOpen() ? null : $req->state->label();
@endphp
@if ($busy)
<article id="vehicle-{{ $v->id }}" class="card card--req group arrive arrive--{{ $state }}" data-controller="arrive" data-arrive-state-value="{{ $state }}" data-arrive-subject-value="v:{{ $v->id }}">
    <a href="/cars/{{ $v->id }}" class="card-link" aria-hidden="true" tabindex="-1"></a>
    <span class="card-media card-media--blank"><span class="skeleton absolute inset-0 rounded-none"></span></span>
    <div class="card-body"><div class="card-title"><span class="skeleton arrive-bar w-2/3"></span></div></div>
    <div class="card-extra">
        <span class="card-sub">
            @if ($state === 'reading')
                <span class="arrive-progress spark-busy"><x-ui.spark class="size-3.5"/>{{ $arrive['what'] === 'photos' ? 'Читаем фото' : 'Читаем документы' }}, {{ $arrive['i'] }} из {{ $arrive['n'] }}</span>
            @else
                <span class="skeleton arrive-bar w-32"></span>
            @endif
        </span>
        <x-park.request-source :req="$req" class="card-source"/>
    </div>
    <div class="card-aside"><x-ui.state tone="soft">{{ $req->type->label() }}</x-ui.state></div>
</article>
@else
<x-park.card :vehicle="$v" :href="'/cars/'.$v->id" :facts="false" link
    :class="'card--req'.($arrive ? ' arrive' : '')" :data-controller="$arrive ? 'arrive' : null" :data-arrive-state-value="$arrive ? $state : null" :data-arrive-subject-value="$arrive ? 'v:'.$v->id : null">
    {{-- Одна строка текста: госномер, логотип страховой с номером убытка (не влезают — строка ужимается,
         fitline) (телефона нет — владелец 07.10.2026: «на карточках не надо») — данные, хвост может уйти в многоточие. --}}
    <span class="card-sub" data-controller="fitline">
        <span class="fit-core"><x-park.ref :vehicle="$v"/><x-ui.plate :value="$v->plate" class="card-sub-plate"/></span>
    </span>
    {{-- Откуда заявка — своей мелкой строкой: адрес и время письма или кто завёл (владелец 07.10.2026). --}}
    <x-park.request-source :req="$req" class="card-source"/>
    <x-slot:aside>
        <x-ui.state :tone="$tone">{{ $word ? mb_strtolower($word) : $req->type->label() }}</x-ui.state>
    </x-slot:aside>
</x-park.card>
@endif
