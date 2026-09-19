{{-- Карта площадки сеткой: ряды одинаковыми клетками, занятая — марка ТС и светофор простоя, нажатие — окошко ТС в списке.
     Колонок столько, сколько мест в самом длинном ряду; на телефоне сетка прокручивается вбок. --}}
@props(['yard', 'occupied'])
@php
    $rows = collect($yard->rows)->map(fn ($r) => ['name' => mb_strtoupper(trim((string) ($r['name'] ?? ''))), 'n' => (int) ($r['n'] ?? 0)])->filter(fn ($r) => $r['n'] > 0);
    $cols = (int) $rows->max('n');
    $named = $rows->contains(fn ($r) => $r['name'] !== '');
@endphp
@if ($cols > 0)
<div class="min-w-0 max-w-full overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    <div class="grid w-max min-w-full gap-1" style="grid-template-columns: {{ $named ? '1.25rem ' : '' }}repeat({{ $cols }}, minmax(2.625rem, 1fr))">
        @foreach ($rows as $row)
            @if ($named)<span class="self-center text-xs text-ink-dim">{{ $row['name'] }}</span>@endif
            @for ($i = 1; $i <= $cols; $i++)
                @if ($i > $row['n'])<span></span>@continue @endif
                @php $spot = $row['name'] === '' ? (string) $i : $row['name'].'-'.$i; $v = $occupied[$spot] ?? null; $tone = \App\Park\Idle::tone($v?->accepted_at ? (int) $v->accepted_at->diffInDays(now()) : null); @endphp
                @if ($v)
                    <a href="/cars?yard={{ $yard->id }}&vid=table&peek={{ $v->id }}" class="flex h-11 flex-col items-center justify-center rounded-lg px-1 text-center leading-tight {{ $tone === 'danger' ? 'bg-danger-soft text-danger' : ($tone === 'urgent' ? 'bg-urgent-soft text-urgent' : 'bg-accent-soft text-accent-text') }}" title="{{ $v->ref }}">
                        <span class="nums text-[10px] opacity-70">{{ $i }}</span><span class="w-full truncate text-xs font-medium">{{ $v->brand?->name ?? $v->ref ?? 'ТС' }}</span>
                    </a>
                @else
                    <span class="flex h-11 items-center justify-center rounded-lg bg-surface-3 text-xs text-ink-dim nums">{{ $i }}</span>
                @endif
            @endfor
        @endforeach
    </div>
</div>
@endif
