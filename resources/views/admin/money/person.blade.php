{{-- «Оплаты» → человек (18.11.2026). Сверху — разделы этого человека пилюлями с числом открытого. Ниже — один список (владелец:
     «разделять на группы не надо, по порядку выставления, не отправленные сверху»): `x-money.person-row` в два этажа, как
     «Предложения». Оплаченное за 30 дней — отдельным блоком снизу той же вёрстки. Строка — окно машины рядом. --}}
@php
    use App\Http\Admin\MoneyController;
    $payout = $tab === 'payouts';
    $name = $user?->name ?? ($payout ? 'Другие получатели' : 'Без менеджера');
    $columns = MoneyController::personTable();
@endphp
<x-ui.shell :title="$name" :detail="$detail">
    <x-ui.pills>
        @foreach (MoneyController::TABS as $k => $t)
            <x-ui.pill :href="MoneyController::personUrl($user, $k)" :current="$k === $tab" data-turbo-action="replace">{{ $t }}@if ($counts[$k])<span class="nums ml-1 opacity-60">{{ $counts[$k] }}</span>@endif</x-ui.pill>
        @endforeach
    </x-ui.pills>

    @if ($rows->isEmpty() && $paid->isEmpty())
        <x-ui.empty class="mt-6">{{ $payout ? 'Выплачивать нечего' : 'Открытых оплат нет' }}</x-ui.empty>
    @endif
    @if ($rows->isNotEmpty())
        <x-ui.table id="money-person" class="mt-4" :table="$columns">
            <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
            @foreach ($rows as $i)<x-money.person-row :table="$columns" :invoice="$i" :tab="$tab"/>@endforeach
        </x-ui.table>
    @endif
    @if ($paid->isNotEmpty())
        <h2 class="list-head mt-6">{{ $payout ? 'Выплачено' : 'Оплачено' }} за 30 дней</h2>
        <x-ui.table id="money-person-paid" :table="$columns">
            @foreach ($paid as $i)<x-money.person-row :table="$columns" :invoice="$i" :tab="$tab" paid :attempts="$attempts"/>@endforeach
        </x-ui.table>
    @endif
</x-ui.shell>
