{{-- Деньги вендора плашками: сводка (просрочено, нам должны, мы должны, не выставлено), ТС с невыставленным
     хранением (строка ведёт в счёт по ТС) и счета. --}}
@php use App\Support\Money; $unbilledTotal = round($unbilled->sum(), 2); @endphp
<div class="flex flex-col gap-2">
    @if ($debt['overdue'] > 0 || $debt['owed_to_us'] > 0 || $debt['we_owe'] > 0 || $unbilledTotal > 0)
        <div class="list">
            @if ($debt['overdue'] > 0)<div class="row justify-between"><span class="text-danger">Просрочено</span><span class="nums font-semibold text-danger">{{ Money::rub($debt['overdue']) }}</span></div>@endif
            @if ($debt['owed_to_us'] > 0)<div class="row justify-between"><span class="text-ink-muted">Нам должны</span><span class="nums font-semibold">{{ Money::rub($debt['owed_to_us']) }}</span></div>@endif
            @if ($debt['we_owe'] > 0)<div class="row justify-between"><span class="text-ink-muted">Мы должны</span><span class="nums font-semibold text-urgent">{{ Money::rub($debt['we_owe']) }}</span></div>@endif
            @if ($unbilledTotal > 0)<div class="row justify-between"><span class="text-ink-muted">Не выставлено</span><span class="nums">{{ Money::rub($unbilledTotal) }}</span></div>@endif
        </div>
    @endif
    @php $toBill = $stored->filter(fn ($v) => ($unbilled[$v->id] ?? 0) > 0); @endphp
    @if ($toBill->isNotEmpty())
        <div class="list-head">Выставить <span class="nums">{{ $toBill->count() }}</span></div>
        <div class="list">
            @foreach ($toBill as $v)
                <a href="/cars/{{ $v->id }}/invoices/new" class="row justify-between">
                    <span class="min-w-0"><span class="block truncate">{{ $v->titleWithYear() }}</span><span class="block truncate text-sm text-ink-muted">{{ $v->ref }}@if ($v->yard) {{ $v->ref ? ',' : '' }} {{ $v->yard->name }}@endif</span></span>
                    <span class="flex shrink-0 items-center gap-1"><span class="nums">{{ Money::rub($unbilled[$v->id]) }}</span><x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></span>
                </a>
            @endforeach
        </div>
    @endif
    <div class="list-head">Счета</div>
    @if ($invoices->isEmpty())
        <x-ui.empty>Счетов ещё не было</x-ui.empty>
    @else
        <div class="list">
            @foreach ($invoices as $i)
                <a href="/money/invoices/{{ $i->id }}" class="row justify-between">
                    <span class="flex min-w-0 items-center gap-2.5"><x-billing.light :invoice="$i"/><span class="min-w-0"><span class="block truncate">{{ $i->isOwed() ? 'Мы должны' : \Illuminate\Support\Str::ucfirst($i->label()) }}</span><span class="block truncate text-sm text-ink-muted">{{ $i->issued_at->translatedFormat('j M') }}@if ($i->vehicle), {{ $i->vehicle->titleWithYear() }}@endif</span></span></span>
                    <span class="nums shrink-0 font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
