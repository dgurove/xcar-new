{{-- История операций: месяц выбором, итоги месяца чипами в заголовке, лента строками по датам. --}}
@php use App\Support\Money; @endphp
<x-ui.cabinet title="История" :back="['Деньги', '/account/money']">
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-xl">История</h2>
        <x-ui.choose name="month" :options="$months" :value="$month?->format('Y-m') ?? ''" default="" title="Месяц" id="month"/>
    </div>
    @if ($totals['paid'] > 0 || $totals['payouts'] > 0 || $totals['offset'] > 0)
        <div class="-mt-2 flex flex-wrap gap-1.5">
            @if ($totals['paid'] > 0)<span class="chip nums">Оплачено {{ Money::rub($totals['paid']) }}</span>@endif
            @if ($totals['payouts'] > 0)<span class="chip nums">Выплачено вам {{ Money::rub($totals['payouts']) }}</span>@endif
            @if ($totals['offset'] > 0)<span class="chip nums">Удержано вами {{ Money::rub($totals['offset']) }}</span>@endif
        </div>
    @endif
    @if ($rows->isEmpty())
        <x-ui.empty>{{ $month ? 'В этом месяце операций не было' : 'Операций пока нет' }}</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($rows as $r)
                @include('cabinet.money.op', ['r' => $r])
            @endforeach
        </div>
    @endif
</x-ui.cabinet>
