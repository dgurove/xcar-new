{{-- Заявка в плитках и строках: карточка ТС целиком нажимается. Две строки: название с госномером и строка
     текста — шаг словом, номер убытка, телефон, исполнитель, вендор; справа тип пилюлей (красная при просрочке)
     и срок. Пересказа под названием нет: «Выдать, реализовано 18 сен» — это то же, что тип
     справа и телефон рядом, а из-за третьей строки на экран влезало вдвое меньше заявок. Кнопок нет. --}}
@props(['req', 'arrive' => null])
@php
    use App\Park\RequestType; use App\Park\Timeline;
    // Заявку завела почта, документы ещё читаются — силуэт плитки (как строка таблицы, `x-park.request-row`).
    $state = $arrive['state'] ?? null;
    $busy = in_array($state, ['ghost', 'reading'], true);
    $tone = $req->isOverdue() ? 'danger' : ($req->isOpen() ? 'soft' : 'closed');
    $v = $req->vehicle;
    // Шаг словом — только у открытой и только там, где он не повторяет дело; у закрытой словом её исход.
    $word = $req->isOpen()
        ? ($req->needsCall() === false && $v->state !== \App\Park\VehicleState::Stored ? Timeline::word($v, $req) : null)
        : $req->state->label();
@endphp
@if ($busy)
<article id="vehicle-{{ $v->id }}" class="card group arrive arrive--{{ $state }}" data-controller="arrive" data-arrive-state-value="{{ $state }}" data-arrive-subject-value="v:{{ $v->id }}">
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
    <div class="card-aside"><x-ui.state tone="soft" class="max-w-24 whitespace-normal sm:max-w-none">{{ $req->type->label() }}</x-ui.state></div>
</article>
@else
<x-park.card :vehicle="$v" :href="'/cars/'.$v->id" :facts="false" link
    :class="$arrive ? 'arrive' : null" :data-controller="$arrive ? 'arrive' : null" :data-arrive-state-value="$arrive ? $state : null" :data-arrive-subject-value="$arrive ? 'v:'.$v->id : null">
    {{-- Одна строка текста: госномер, логотип страховой с номером убытка (не влезают — строка ужимается,
         fitline), телефон, исполнитель, парковка перестановки — данные, хвост может уйти в многоточие. Шаг словом —
         свой текст, он не режется (05.10.2026): справа вместо типа. --}}
    <span class="card-sub" data-controller="fitline">
        <span class="fit-core"><x-park.ref :vehicle="$v"/></span>
        @if ($req->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $req->contact_phone) }}" class="text-accent-text">{{ $req->contact_phone }}</a>@endif
        @if ($req->assignee)<span>{{ $req->assignee->shortName() }}</span>@endif
        @if ($req->type === RequestType::Move && $req->yard)<span>{{ $req->yard->name }}</span>@endif
    </span>
    {{-- Откуда заявка — своей мелкой строкой: адрес и время письма или кто завёл (владелец 07.10.2026). --}}
    <x-park.request-source :req="$req" class="card-source"/>
    <x-slot:aside>
        {{-- Шаг словом точнее типа («нужен эвакуатор», а не «Эвакуация») — он и стоит на месте типа, целиком. --}}
        <x-ui.state :tone="$tone" class="max-w-24 whitespace-normal sm:max-w-none">{{ $word ? mb_strtolower($word) : $req->type->label() }}</x-ui.state>
        @if ($req->planned_at)<span class="nums text-xs {{ $req->isOverdue() ? 'text-danger' : 'text-ink-dim' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
    </x-slot:aside>
</x-park.card>
@endif
