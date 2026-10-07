{{-- Реквизиты: мы первой строкой, дальше контрагенты; строка — шторка с формой; новая — кнопкой внизу. --}}
@php use App\Billing\PartyKind; @endphp
<x-ui.shell title="Реквизиты">
    <x-ui.toolbar :sort="$sort" name="parties" class="mb-4"/>
    <div class="list">
        @foreach ($parties as $p)
            <div class="row" data-controller="sheet">
                <button type="button" class="contents text-left" data-action="sheet#open">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate"><x-vendor.name :party="$p"/>@if ($p->is_self) <span class="text-ink-dim">мы</span>@endif</span>
                        <span class="row-sub">
                            <span>{{ $p->kind->label() }}</span>
                            @if ($p->inn)<span class="nums">ИНН {{ $p->inn }}</span>@endif
                            @if ($p->account)<span class="nums">р/с {{ $p->account }}</span>@endif
                        </span>
                    </span>
                    @if (! $p->billable() && ($p->is_self || $p->kind !== PartyKind::Person || $p->invoices_count))
                        <x-ui.state tone="urgent" class="shrink-0">{{ $p->filled() ? 'Нет банка' : 'Нет реквизитов' }}</x-ui.state>
                    @elseif ($p->invoices_count)
                        <span class="nums shrink-0 text-sm text-ink-dim">{{ $p->invoices_count }} {{ \App\Support\Plural::of($p->invoices_count, ['счёт', 'счёта', 'счетов']) }}</span>
                    @endif
                    <x-ui.chevron/>
                </button>
                <x-ui.sheet id="party-{{ $p->id }}" :title="$p->name" wide tall>@include('park.money.party-form', ['party' => $p])</x-ui.sheet>
            </div>
        @endforeach
    </div>
    <div class="mt-3" data-controller="sheet">
        <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Контрагент</x-ui.button>
        <x-ui.sheet id="party-new" title="Контрагент" wide tall :open="$errors->has('name')">@include('park.money.party-form', ['party' => null])</x-ui.sheet>
    </div>
</x-ui.shell>
