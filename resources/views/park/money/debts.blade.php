{{-- Долги по контрагентам: итоги сверху, сортировка и поиск в тулбаре, строка — нам должны, мы должны, просрочено, не выставлено; ведёт к счетам контрагента. --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Долги" :back="['Деньги', '/money']" narrow>
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <x-ui.stat :value="Money::rub($totals['owed_to_us'])" label="Нам должны"/>
        <x-ui.stat :value="Money::rub($totals['we_owe'])" label="Мы должны"/>
        <x-ui.stat :value="Money::rub($totals['overdue'])" label="Просрочено" :class="$totals['overdue'] > 0 ? 'text-danger' : ''"/>
        <x-ui.stat :value="Money::rub($totals['unbilled'])" label="Не выставлено"/>
    </div>
    <x-ui.toolbar :sorts="\App\Http\Park\MoneyController::DEBT_SORTS" :sort="$sort" name="debts">
        <x-slot:filters><input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Контрагент" enterkeyhint="search"></x-slot:filters>
    </x-ui.toolbar>
    @if ($debts->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Долгов нет' }}</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
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
        <div class="mt-8"><x-ui.pager :of="$debts"/></div>
    @endif
</x-ui.shell>
