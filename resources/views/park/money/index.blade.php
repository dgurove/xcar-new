{{-- Деньги: счета с пресетами, сортировкой, поиском и контрагентом в фильтрах; три вида — плитки x-billing.card,
     строки x-billing.row, таблица с окошком; «Долги» и «Месяц» — строки над списком; счёт выставляется из ТС. --}}
@php use App\Support\ListView; $view = ListView::pick(request(), $invoices->total()); @endphp
<x-ui.shell :title="$party ? $party->name : 'Деньги'" :count="$invoices->total()" :back="$party ? ['Долги', '/money/debts'] : false" :phone-heading="(bool) $party">
    <x-ui.toolbar :sorts="\App\Http\Park\MoneyController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['party' => request('party'), 'car' => request('car'), ListView::PARAM => request(ListView::PARAM)])" name="money">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Номер, контрагент, убыток, VIN" enterkeyhint="search">
            @if ($parties->isNotEmpty())<select name="party" class="field-input"><option value="">Все контрагенты</option>@foreach ($parties as $id => $name)<option value="{{ $id }}" @selected((string) request('party') === (string) $id)>{{ $name }}</option>@endforeach</select>@endif
        </x-slot:filters>
    </x-ui.toolbar>
    @unless ($party)
        <div class="mt-4 flex flex-col gap-2">
            <a href="/money/debts" class="row !py-3"><span class="min-w-0 flex-1 font-medium">Долги по контрагентам</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
            <a href="/money/summary" class="row !py-3"><span class="min-w-0 flex-1 font-medium">Месяц</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
        </div>
    @endunless
    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Счетов нет' }}</x-ui.empty>
    @elseif ($view === ListView::TABLE)
        <x-ui.table id="invoices" class="mt-6">
            <x-slot:head><tr><th>№</th><th class="grow">Контрагент</th><th>Срок</th><th class="num">Сумма</th><th class="num">Остаток</th></tr></x-slot:head>
            @foreach ($invoices as $i)<x-billing.table-row :invoice="$i"/>@endforeach
        </x-ui.table>
    @elseif ($view === ListView::GRID)
        <div class="mt-6 {{ ListView::containerClass($view) }}">@foreach ($invoices as $i)<x-billing.card :invoice="$i"/>@endforeach</div>
    @else
        <div class="mt-6 flex flex-col gap-2">@foreach ($invoices as $i)<x-billing.row :invoice="$i"/>@endforeach</div>
    @endif
    @if ($invoices->isNotEmpty())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="ListView::perSizes($view)"/></div>@endif
</x-ui.shell>
