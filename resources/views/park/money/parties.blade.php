{{-- Реквизиты: мы первой строкой, дальше контрагенты; строка — шторка с формой; новая — кнопкой внизу. --}}
@php use App\Billing\PartyKind; @endphp
<x-ui.shell title="Реквизиты" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($parties as $p)
            <div class="row" data-controller="sheet">
                <button type="button" class="contents text-left" data-action="sheet#open">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $p->name }}@if ($p->is_self) <span class="chip text-xs">мы</span>@endif @if ($p->is_self && $p->bankMissing())<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">Заполните реквизиты банка</x-ui.pill>@endif</span>
                        <span class="row-sub mt-1 flex flex-wrap gap-1.5">
                            <span class="chip">{{ $p->kind->label() }}</span>
                            @if ($p->inn)<span class="tag nums">ИНН {{ $p->inn }}</span>@endif
                            @if ($p->account)<span class="tag nums">р/с {{ $p->account }}</span>@endif
                            @if ($p->invoices_count)<span class="tag nums">счетов {{ $p->invoices_count }}</span>@endif
                        </span>
                    </span>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </button>
                <x-ui.sheet id="party-{{ $p->id }}" :title="$p->name" wide>@include('park.money.party-form', ['party' => $p])</x-ui.sheet>
            </div>
        @endforeach
        <div data-controller="sheet">
            <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Контрагент</x-ui.button>
            <x-ui.sheet id="party-new" title="Контрагент" wide :open="$errors->has('name')">@include('park.money.party-form', ['party' => null])</x-ui.sheet>
        </div>
    </div>
</x-ui.shell>
