{{-- Долги по контрагентам: сортировка и поиск в тулбаре, строка — нам должны, мы должны, просрочено, не выставлено; ведёт к счетам контрагента. --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Долги" :back="['Оплаты', '/money']">
    <x-ui.toolbar :sorts="\App\Http\Park\MoneyController::DEBT_SORTS" :sort="$sort" name="debts">
        <x-slot:filters><input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Контрагент" enterkeyhint="search"></x-slot:filters>
    </x-ui.toolbar>
    @if ($debts->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Долгов нет' }}</x-ui.empty>
    @else
        {{-- Строка — контрагент, под ним словами что по нему; справа главное: просрочено красным, иначе нам должны. --}}
        <div class="list mt-6">
            @foreach ($debts as $d)
                <a href="{{ $d['party']->id ? '/money?preset=all&party='.$d['party']->id : '/money' }}" class="row">
                    <div class="min-w-0 flex-1">
                        <div class="truncate"><x-vendor.name :party="$d['party']"/></div>
                        <div class="row-sub">
                            @if ($d['overdue'] > 0)<span class="text-danger">просрочено</span>@endif
                            @if ($d['overdue'] > 0 && $d['owed_to_us'] > $d['overdue'])<span class="nums">нам {{ Money::rub($d['owed_to_us']) }}</span>@endif
                            @if ($d['we_owe'] > 0)<span class="nums text-urgent">мы должны {{ Money::rub($d['we_owe']) }}</span>@endif
                            @if ($d['unbilled'] > 0)<span class="nums">не выставлено {{ Money::rub($d['unbilled']) }}</span>@endif
                        </div>
                    </div>
                    @if ($d['overdue'] > 0)<span class="nums shrink-0 text-danger">{{ Money::rub($d['overdue']) }}</span>
                    @elseif ($d['owed_to_us'] > 0)<span class="nums shrink-0">{{ Money::rub($d['owed_to_us']) }}</span>@endif
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
        </div>
        <div class="mt-8"><x-ui.pager :of="$debts"/></div>
    @endif
</x-ui.shell>
