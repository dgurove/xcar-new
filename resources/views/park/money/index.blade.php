{{-- Деньги: счета с пресетами, сортировкой, лупой и чипом «Контрагент» — только таблицей с карточкам
     (плиток и строк у счетов нет); «Долги», «Закрытие месяца» и «Реквизиты» — одной плашкой над списком; счёт выставляется
     из ТС. --}}
@php use App\Support\ListView; $view = ListView::fromRequest(request()) === ListView::WIDE ? ListView::WIDE : ListView::TABLE; @endphp
<x-ui.shell :title="$party ? $party->name : 'Оплаты'" :count="$invoices->total()" :back="$party ? ['Долги', '/money/debts'] : false" :phone-heading="(bool) $party" :detail="$detail">
    <x-slot:lead>@if ($partyVendor = $party ? \App\Vendors\Vendor::ofParty($party->id) : null)<x-vendor.logo :vendor="$partyVendor"/>@endif</x-slot:lead>
    <x-ui.toolbar :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" name="money" :facets="$facets" search="Номер, контрагент, убыток, VIN">
        {{-- Счета — только таблицей; на телефоне её можно развернуть в подробную. --}}
        <x-slot:extra><x-ui.view-switch :views="[ListView::TABLE, ListView::WIDE]" :current="$view"/></x-slot:extra>
    </x-ui.toolbar>
    @unless ($party)
        <div class="list mt-4">
            {{-- Прошлый месяц не закрыт — первой строкой: месяц, сколько ТС и на какую сумму, что мешает. --}}
            @php $closingMonth = \Illuminate\Support\Carbon::parse($closing['month']); @endphp
            <a href="/money/closing?month={{ $closingMonth->format('Y-m') }}" class="row">
                <span class="min-w-0 flex-1">
                    <span class="block">Закрытие месяца</span>
                    <span class="row-sub"><span>{{ mb_strtolower($closingMonth->translatedFormat('F')) }}</span>@if ($closing['cars'])<span class="nums whitespace-nowrap">{{ $closing['cars'] }}&nbsp;ТС</span><span class="nums">{{ \App\Support\Money::rub($closing['amount']) }}</span>@endif</span>
                </span>
                @if ($closing['blocked'])<span class="shrink-0 text-sm text-urgent">нужны реквизиты</span>
                @elseif ($closing['count'])<span class="nums shrink-0 text-sm text-urgent">выставить {{ $closing['count'] }}</span>@endif
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
            <a href="/money/debts" class="row"><span class="min-w-0 flex-1">Долги по контрагентам</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
            @if (auth()->user()->canManagePark())<a href="/money/parties" class="row"><span class="min-w-0 flex-1">Реквизиты</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>@endif
        </div>
    @endunless
    <div id="list">
    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Счетов нет' }}</x-ui.empty>
    @else
        @php $columns = \App\Http\Park\MoneyController::table(); @endphp
        <x-ui.table id="invoices" class="mt-6" :view="$view" :rest="$columns->rest()">
            <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
            @foreach ($invoices as $i)<x-billing.table-row :invoice="$i" :table="$columns"/>@endforeach
        </x-ui.table>
    @endif
    @if ($invoices->hasPages())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="ListView::PER_ROWS"/></div>@endif
    </div>
</x-ui.shell>
