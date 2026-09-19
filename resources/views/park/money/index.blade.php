{{-- Деньги: счета с пресетами и окошком строки; «Долги» и «Месяц» — строки над списком; главное действие — реквизиты не нужны, счёт выставляется из ТС. --}}
@php $view = \App\Support\ListView::pick(request(), $invoices->total()); @endphp
<x-ui.shell :title="$party ? $party->name : 'Деньги'" :count="$invoices->total()" :back="$party ? ['Долги', '/money/debts'] : false" :phone-heading="(bool) $party">
    <x-ui.toolbar :sorts="\App\Http\Park\MoneyController::SORTS" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['party' => request('party'), 'car' => request('car'), \App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)])" name="money">
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
    </x-ui.toolbar>
    @unless ($party)
        <div class="mt-4 flex flex-col gap-2">
            <a href="/money/debts" class="row !py-3"><span class="min-w-0 flex-1 font-medium">Долги по контрагентам</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
            <a href="/money/summary" class="row !py-3"><span class="min-w-0 flex-1 font-medium">Месяц</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
        </div>
    @endunless
    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">Счетов нет</x-ui.empty>
    @elseif ($view === \App\Support\ListView::TABLE)
        <x-ui.table id="invoices" class="mt-6">
            <x-slot:head><tr><th>№</th><th class="grow">Контрагент</th><th>Срок</th><th class="num">Сумма</th><th class="num">Остаток</th></tr></x-slot:head>
            @foreach ($invoices as $i)<x-billing.table-row :invoice="$i"/>@endforeach
        </x-ui.table>
    @else
        <div class="mt-6 flex flex-col gap-2">@foreach ($invoices as $i)<x-billing.card :invoice="$i"/>@endforeach</div>
    @endif
    @if ($invoices->isNotEmpty())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="\App\Support\ListView::perSizes($view)"/></div>@endif
</x-ui.shell>
