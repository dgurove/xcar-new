{{-- Долги по контрагентам: нам должны, мы должны, просрочено, не выставлено. Строка ведёт к счетам контрагента. --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Долги" :back="['Деньги', '/money']" narrow>
    @if ($debts->isEmpty())
        <x-ui.empty>Долгов нет</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($debts as $d)
                <a href="{{ $d['party']->id ? '/money?preset=all&party='.$d['party']->id : '/money' }}" class="row">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $d['party']->name }}</div>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @if ($d['overdue'] > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">просрочено {{ Money::rub($d['overdue']) }}</x-ui.pill>@endif
                            @if ($d['owed_to_us'] > 0)<span class="chip nums">нам {{ Money::rub($d['owed_to_us']) }}</span>@endif
                            @if ($d['we_owe'] > 0)<span class="chip nums text-urgent">мы должны {{ Money::rub($d['we_owe']) }}</span>@endif
                            @if ($d['unbilled'] > 0)<span class="tag nums">не выставлено {{ Money::rub($d['unbilled']) }}</span>@endif
                        </div>
                    </div>
                    <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
