{{-- Деньги вендора: долг в обе стороны, не выставленное хранение по его ТС (кнопка «Счёт» ведёт на стоянку), все счета. --}}
@php use App\Support\Money; use App\Support\Surface; $unbilledTotal = round($unbilled->sum(), 2); @endphp
@if ($debt['overdue'] > 0 || $debt['owed_to_us'] > 0 || $debt['we_owe'] > 0 || $unbilledTotal > 0)
    <div class="flex flex-wrap gap-1.5">
        @if ($debt['overdue'] > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">просрочено {{ Money::rub($debt['overdue']) }}</x-ui.pill>@endif
        @if ($debt['owed_to_us'] > 0)<span class="chip nums">нам {{ Money::rub($debt['owed_to_us']) }}</span>@endif
        @if ($debt['we_owe'] > 0)<span class="chip nums text-urgent">мы должны {{ Money::rub($debt['we_owe']) }}</span>@endif
        @if ($unbilledTotal > 0)<span class="tag nums">не выставлено {{ Money::rub($unbilledTotal) }}</span>@endif
    </div>
@endif
@if ($stored->isNotEmpty())
    <div class="mt-6 flex flex-col gap-2">
        @foreach ($stored as $v)
            <div class="row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2"><a href="{{ Surface::Park->url('/cars/'.$v->id) }}" class="truncate font-medium" data-turbo="false">{{ $v->titleWithYear() }}</a>@if ($v->ref)<span class="nums text-sm text-ink-muted">{{ $v->ref }}</span>@endif</div>
                    <div class="mt-1.5 flex flex-wrap gap-1.5"><x-park.state :vehicle="$v"/>@if ($v->yard)<x-ui.place class="tag">{{ $v->yard->name }}</x-ui.place>@endif</div>
                </div>
                @if (($unbilled[$v->id] ?? 0) > 0)
                    <a href="{{ Surface::Park->url('/cars/'.$v->id.'/invoices/new') }}" class="btn btn-s btn-quiet nums shrink-0" data-turbo="false">Счёт {{ Money::rub($unbilled[$v->id]) }}</a>
                @endif
            </div>
        @endforeach
    </div>
@endif
@if ($invoices->isEmpty())
    <x-ui.empty class="mt-6">Счетов с этим вендором ещё не было</x-ui.empty>
@else
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
