{{-- Оплаты (раздел CRM, бывшие «Деньги»; адрес прежний): по сделкам и разовые оплаты услуг по ссылке («+» — только админу); пресеты, сортировка и поиск в тулбаре, «Расчёты с менеджерами» строкой над списком,
     сами счета и вознаграждения — только таблицей с карточкам; действия в карточке, в строках их нет. --}}
@php use App\Support\ListView; $view = ListView::fromRequest(request()) === ListView::WIDE ? ListView::WIDE : ListView::TABLE; @endphp
<x-ui.shell title="Оплаты" :heading="false" :detail="$detail">
    <x-admin.work-titles current="money" :count="$invoices->total()"/>
    <x-ui.toolbar class="mt-5" :sort="$sort" :pills="\App\Http\Admin\MoneyController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['claims' => !empty($counts['claims']) ? 'pill-urgent' : '']" name="money" :facets="$facets" search="Менеджер, № предложения, ТС, услуга" search-target="#invoices-list">
        <x-slot:extra><x-ui.view-switch :views="[ListView::TABLE, ListView::WIDE]" :current="$view"/></x-slot:extra>
        @if (auth()->user()->isAdmin() && app(\App\Billing\Acquiring\Gateway::class)->configured())
            <x-slot:actions>
                @include('admin.money.service-sheet')
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    <div class="list mt-4">
        <a href="/work/money/managers" class="row"><span class="min-w-0 flex-1">Расчёты с менеджерами</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
        @php $unmatched = \App\Billing\Bank\Transaction::where('state', \App\Billing\Bank\Transaction::UNMATCHED)->count(); @endphp
        <a href="/work/money/bank" class="row"><span class="min-w-0 flex-1">Поступления</span>@if ($unmatched)<span class="badge nums">{{ $unmatched }}</span>@endif<x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
    </div>
    <div id="invoices-list">
    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : match ($preset) { 'claims' => 'Никто об оплате не сообщал', 'payouts' => 'Выплачивать нечего', default => 'Счетов нет' } }}</x-ui.empty>
    @else
        <x-ui.table id="invoices" class="mt-6" :view="$view">
            <x-slot:head><tr><th class="grow">Кто</th><x-ui.th :sort="$sort" key="fresh" class="cell-dim hidden sm:table-cell">№</x-ui.th><th class="cell-dim col-detail-hide hidden lg:table-cell">ТС</th><x-ui.th :sort="$sort" key="due" class="hidden sm:table-cell">Срок</x-ui.th><x-ui.th :sort="$sort" key="amount" class="num">Сумма</x-ui.th><x-ui.th :sort="$sort" key="rest" class="num hidden sm:table-cell">Остаток</x-ui.th></tr></x-slot:head>
            @foreach ($invoices as $i)<x-money.table-row :invoice="$i"/>@endforeach
        </x-ui.table>
    @endif
    @if ($invoices->hasPages())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="ListView::PER_ROWS"/></div>@endif
    </div>
</x-ui.shell>
