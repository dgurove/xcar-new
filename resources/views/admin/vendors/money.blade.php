{{-- Деньги вендора: долг в обе стороны и последние счета; счета живут на стоянке. --}}
@php use App\Support\Money; use App\Support\Surface; @endphp
@if (!$party)
    <x-ui.empty>Счетов с этим вендором ещё не было</x-ui.empty>
@else
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <x-ui.stat :value="Money::rub($debt['owed_to_us'])" label="Нам должны"/>
        <x-ui.stat :value="Money::rub($debt['we_owe'])" label="Мы должны"/>
        <x-ui.stat :value="Money::rub($debt['overdue'])" label="Просрочено" :class="$debt['overdue'] > 0 ? 'text-danger' : ''"/>
    </div>
    <div class="mt-6 flex flex-col gap-2">
        @foreach ($invoices as $i)
            <a href="{{ Surface::Park->url('/money/invoices/'.$i->id) }}" class="row" data-turbo="false">
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2"><span class="font-medium">{{ $i->isOwed() ? 'Мы должны' : $i->label() }}</span><span class="nums text-sm text-ink-muted">{{ $i->issued_at->translatedFormat('j M') }}</span></div>
                    <div class="mt-1.5 flex flex-wrap gap-1.5"><x-billing.light :invoice="$i"/><span class="chip">{{ $i->kind->label() }}</span>@if ($i->vehicle)<span class="tag">{{ $i->vehicle->titleWithYear() }}</span>@endif</div>
                </div>
                <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
            </a>
        @endforeach
    </div>
@endif
