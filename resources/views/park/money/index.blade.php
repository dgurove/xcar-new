{{-- Деньги: счета с пресетами, сортировкой, поиском и контрагентом в фильтрах — только таблицей с окошком
     (плиток и строк у счетов нет); «Долги» и «Закрытие месяца» — одной плашкой над списком; счёт выставляется
     из ТС. --}}
@php use App\Support\ListView; @endphp
<x-ui.shell :title="$party ? $party->name : 'Деньги'" :count="$invoices->total()" :back="$party ? ['Долги', '/money/debts'] : false" :phone-heading="(bool) $party">
    <x-ui.toolbar :sorts="\App\Http\Park\MoneyController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['party' => request('party'), 'car' => request('car')])" name="money">
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Номер, контрагент, убыток, VIN" enterkeyhint="search">
            @if ($parties->isNotEmpty())<select name="party" class="field-input"><option value="">Все контрагенты</option>@foreach ($parties as $id => $name)<option value="{{ $id }}" @selected((string) request('party') === (string) $id)>{{ $name }}</option>@endforeach</select>@endif
        </x-slot:filters>
    </x-ui.toolbar>
    @unless ($party)
        <div class="list mt-4">
            <a href="/money/debts" class="row"><span class="min-w-0 flex-1">Долги по контрагентам</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
            <a href="/money/closing" class="row"><span class="min-w-0 flex-1">Закрытие месяца</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
        </div>
    @endunless
    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Счетов нет' }}</x-ui.empty>
    @else
        <x-ui.table id="invoices" class="mt-6">
            <x-slot:head><tr><th class="grow">Контрагент</th><th class="cell-dim hidden sm:table-cell">№</th><th class="cell-dim col-peek-hide hidden lg:table-cell">За что</th><th class="hidden sm:table-cell">Срок</th><th class="num">Сумма</th><th class="num hidden sm:table-cell">Остаток</th></tr></x-slot:head>
            @foreach ($invoices as $i)<x-billing.table-row :invoice="$i"/>@endforeach
        </x-ui.table>
    @endif
    @if ($invoices->hasPages())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="ListView::PER_ROWS"/></div>@endif
</x-ui.shell>
