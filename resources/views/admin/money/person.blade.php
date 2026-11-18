{{-- «Оплаты» → человек (18.11.2026). Сверху — разделы этого человека пилюлями с числом открытого. Ниже — одна таблица
     (`x-money.person-row`, два этажа, как «Предложения») тремя группами с суммой справа (владелец: «три подзаголовка — не
     выставлены, выставлены, оплачены за последние 30д, справа сумма»; подзаголовки — `x-table.group`, как у «Предложений»).
     Внутри группы — по дню выставления, дольше ждущее выше. Строка — окно машины рядом. --}}
@php
    use App\Http\Admin\MoneyController; use App\Support\Money;
    $payout = $tab === 'payouts';
    $name = $user?->name ?? ($payout ? 'Другие получатели' : 'Без менеджера');
    $columns = MoneyController::personTable();
    $unsent = $rows->reject(fn ($i) => $i->isShownToManager());
    $issued = $rows->filter(fn ($i) => $i->isShownToManager());
    $sum = fn ($v) => '<span class="table-group-aside"><span class="nums">'.e(Money::rub($v)).'</span></span>';
@endphp
<x-ui.shell :title="$name" :detail="$detail">
    <x-ui.pills>
        @foreach (MoneyController::TABS as $k => $t)
            <x-ui.pill :href="MoneyController::personUrl($user, $k)" :current="$k === $tab" data-turbo-action="replace">{{ $t }}@if ($counts[$k])<span class="nums ml-1 opacity-60">{{ $counts[$k] }}</span>@endif</x-ui.pill>
        @endforeach
    </x-ui.pills>

    @if ($rows->isEmpty() && $paid->isEmpty())
        <x-ui.empty class="mt-6">{{ $payout ? 'Выплачивать нечего' : 'Открытых оплат нет' }}</x-ui.empty>
    @else
        <x-ui.table id="money-person" class="mt-4" :table="$columns">
            <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
            @if ($unsent->isNotEmpty())
                <x-table.group :table="$columns" key="unsent" title="Не выставлены" :count="$unsent->count()">{!! $sum($unsent->sum(fn ($i) => $i->remaining())) !!}</x-table.group>
                @foreach ($unsent as $i)<x-money.person-row :table="$columns" :invoice="$i" :tab="$tab"/>@endforeach
            @endif
            @if ($issued->isNotEmpty())
                <x-table.group :table="$columns" key="issued" :title="$payout ? 'К выплате' : 'Выставлены'" :count="$issued->count()">{!! $sum($issued->sum(fn ($i) => $i->remaining())) !!}</x-table.group>
                @foreach ($issued as $i)<x-money.person-row :table="$columns" :invoice="$i" :tab="$tab"/>@endforeach
            @endif
            @if ($paid->isNotEmpty())
                <x-table.group :table="$columns" key="paid" :title="($payout ? 'Выплачены' : 'Оплачены').' за 30 дней'" :count="$paid->count()">{!! $sum($paid->sum('total')) !!}</x-table.group>
                @foreach ($paid as $i)<x-money.person-row :table="$columns" :invoice="$i" :tab="$tab" paid :attempts="$attempts"/>@endforeach
            @endif
        </x-ui.table>
    @endif
</x-ui.shell>
