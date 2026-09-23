{{-- Заявка в плитках и строках: карточка ТС целиком нажимается. Две строки: название и ряд чипов — номер убытка
     (копируется), шаг словом, где он не дублирует дело, вендор, телефон, исполнитель; справа тип заявки пилюлей
     (красная при просрочке) и срок. Пересказа под названием нет: «Выдать, продана 18 сен» — это то же, что тип
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
    @if ($v->ref)<x-ui.copy-code class="tag" :value="$v->ref"/>@endif
    @if ($word)<span class="tag">{{ $word }}</span>@endif
    @if ($req->type === RequestType::Move && $req->yard)<x-ui.place class="tag">{{ $req->yard->name }}</x-ui.place>@endif
    @if ($v->vendor)<span class="tag">{{ $v->vendor->name }}</span>@endif
    @if ($req->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $req->contact_phone) }}" class="tag nums"><x-ui.icon name="phone" class="size-3.5 shrink-0"/>{{ $req->contact_phone }}</a>@endif
    @if ($req->assignee)<x-ui.person :user="$req->assignee" class="tag"/>@endif
    <x-slot:aside>
        <x-ui.pill :tone="$tone" class="!min-h-0 !py-0.5 text-xs">{{ $req->type->label() }}</x-ui.pill>
        @if ($req->planned_at)<span class="nums text-xs {{ $req->isOverdue() ? 'text-danger' : 'text-ink-dim' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
    </x-slot:aside>
</x-park.card>
