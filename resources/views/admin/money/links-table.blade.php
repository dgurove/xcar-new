{{-- «По ссылкам» таблицей — все ссылки на оплату одним списком, свежие сверху (09.10.2026, владелец: «один список из
     ссылок с сортировкой по дате создания, у всех подписана марка, модель и номер, и подписано, какие созданы сделкой, а
     какие вручную»). Строка — ссылка (`MoneyLinks`): кто платит, ТС и номер своими столбцами, откуда словом, что с ней
     цветным словом, когда и кто взял, сумма. Отменённые — только чипом «Отменённые», бледными. Без групп: порядок — дата
     или поле сортировки. На телефоне — название в два этажа: под плательщиком состояние, ТС и откуда. --}}
@php
    use App\Http\Admin\MoneyLinks as L; use App\Support\Money; use App\Billing\Acquiring\PayLinkState;
    $items = $links instanceof \Illuminate\Support\Collection ? $links : collect($links->items());
    $when = fn ($t) => $t->translatedFormat($t->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i');
    $sortable = $titled ? null : $sort;
@endphp
@if (! ($titled && $items->isEmpty()))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS['links'] }}</h2>@endif
    @if ($items->isNotEmpty())
    <x-ui.table id="money-links" class="mt-2">
        <x-slot:head><tr>
            <x-ui.th :sort="$sortable" key="payer" class="grow">Кто платит</x-ui.th>
            <x-ui.th class="hidden sm:table-cell">Марка, модель</x-ui.th>
            <x-ui.th class="col-detail-hide hidden lg:table-cell">№</x-ui.th>
            <x-ui.th class="col-detail-hide hidden lg:table-cell">Откуда</x-ui.th>
            <x-ui.th class="hidden sm:table-cell">Сейчас</x-ui.th>
            <x-ui.th :sort="$sortable" key="created" class="num col-detail-hide hidden sm:table-cell">Создано</x-ui.th>
            <x-ui.th :sort="$sortable" key="amount" class="num">Сумма</x-ui.th>
        </tr></x-slot:head>
        @foreach ($items as $l)
            @php
                [$car, $number] = L::car($l);
                [$state, $tone] = L::state($l);
                $by = L::creator($l);
                $off = $l->state === PayLinkState::Canceled;
            @endphp
            <tr data-detail-key="{{ $l->id }}" data-search-row @class(['opacity-50' => $off])>
                <td class="grow">
                    <x-ui.row-link :key="$l->id" :with="['tab' => 'links']"><span class="cell-title">{{ $l->payerName() }}</span></x-ui.row-link>
                    <span class="cell-sub sm:hidden">
                        <span class="{{ $tone }}">{{ $state }}</span>
                        @if ($car)<span>{{ $car }}@if ($number), № {{ $number }}@endif</span>@endif
                        <span>{{ L::from($l) }}</span>
                    </span>
                </td>
                <td class="hidden sm:table-cell">@if ($car){{ $car }}@else<span class="text-ink-dim">не указано</span>@endif</td>
                <td class="cell-dim col-detail-hide hidden lg:table-cell nums">{{ $number }}</td>
                <td class="cell-dim col-detail-hide hidden lg:table-cell">{{ L::from($l) }}</td>
                <td class="hidden sm:table-cell {{ $tone }}">{{ $state }}</td>
                <td class="num cell-dim col-detail-hide hidden sm:table-cell nums">{{ $when($l->created_at) }}@if ($by)<span class="text-ink-dim">, {{ $by->shortName() }}</span>@endif</td>
                <td class="num nums {{ $l->state === PayLinkState::Paid ? 'text-open' : '' }}">{{ Money::nums($l->amount) }}</td>
            </tr>
        @endforeach
    </x-ui.table>
    @if (! $links instanceof \Illuminate\Support\Collection && $links->hasPages())<div class="mt-8"><x-ui.pager :of="$links"/></div>@endif
    @endif
</section>
@endif
