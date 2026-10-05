{{-- Заявка в плитках и строках: карточка ТС целиком нажимается. Две строки: название с госномером и строка
     текста — шаг словом, номер убытка, телефон, исполнитель, вендор; справа тип пилюлей (красная при просрочке)
     и срок. Пересказа под названием нет: «Выдать, реализовано 18 сен» — это то же, что тип
     справа и телефон рядом, а из-за третьей строки на экран влезало вдвое меньше заявок. Кнопок нет. --}}
@props(['req'])
@php
    use App\Park\RequestType; use App\Park\Timeline;
    $tone = $req->isOverdue() ? 'danger' : ($req->isOpen() ? 'soft' : 'closed');
    $v = $req->vehicle;
    // Шаг словом — только у открытой и только там, где он не повторяет дело; у закрытой словом её исход.
    $word = $req->isOpen()
        ? ($req->needsCall() === false && $v->state !== \App\Park\VehicleState::Stored ? Timeline::word($v, $req) : null)
        : $req->state->label();
@endphp
<x-park.card :vehicle="$v" :href="'/cars/'.$v->id" :facts="false" link>
    {{-- Одна строка текста: госномер, логотип страховой с номером убытка (не влезают — строка ужимается,
         fitline), телефон, исполнитель, парковка перестановки — данные, хвост может уйти в многоточие. Шаг словом —
         свой текст, он не режется (05.10.2026): справа вместо типа. --}}
    <span class="card-sub" data-controller="fitline">
        <span class="fit-core"><x-park.ref :vehicle="$v"/></span>
        @if ($req->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $req->contact_phone) }}" class="text-accent-text">{{ $req->contact_phone }}</a>@endif
        @if ($req->assignee)<span>{{ $req->assignee->shortName() }}</span>@endif
        @if ($req->type === RequestType::Move && $req->yard)<span>{{ $req->yard->name }}</span>@endif
    </span>
    <x-slot:aside>
        {{-- Шаг словом точнее типа («нужен эвакуатор», а не «Эвакуация») — он и стоит на месте типа, целиком. --}}
        <x-ui.state :tone="$tone" class="max-w-24 whitespace-normal sm:max-w-none">{{ $word ? mb_strtolower($word) : $req->type->label() }}</x-ui.state>
        @if ($req->planned_at)<span class="nums text-xs {{ $req->isOverdue() ? 'text-danger' : 'text-ink-dim' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
    </x-slot:aside>
</x-park.card>
