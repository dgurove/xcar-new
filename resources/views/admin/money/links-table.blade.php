{{-- «По ссылкам» таблицей — все ссылки на оплату одним списком, свежие сверху (09.10.2026, владелец: «один список из
     ссылок с сортировкой по дате создания, у всех подписана марка, модель и номер, и подписано, какие созданы сделкой, а
     какие вручную»). Строка — ссылка (`MoneyLinks`). На ПК: кто платит, ТС с номером серым, статус коротким словом цветом
     (`MoneyLinks::short`, полная фраза — в карточке), когда и кто взял, сумма. На телефоне — как строка банка: имя и
     сумма, под ними ТС и статус под суммой. По дате — группами по дням («Сегодня», «Вчера», «5 октября»), как история
     операций. Отменённые — только чипом «Отменённые», бледными. --}}
@php
    use App\Http\Admin\MoneyLinks as L; use App\Support\Money; use App\Billing\Acquiring\PayLinkState;
    $items = $links instanceof \Illuminate\Support\Collection ? $links : collect($links->items());
    $when = fn ($t) => $t->translatedFormat($t->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i');
    $sortable = $titled ? null : $sort;
    $days = ! $titled && ($sort?->key ?? 'created') === 'created' ? $items->groupBy(fn ($l) => L::day($l->created_at)) : collect(['' => $items]);
@endphp
@if (! ($titled && $items->isEmpty()))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS['links'] }}</h2>@endif
    @if ($items->isNotEmpty())
    <x-ui.table id="money-links" class="mt-2">
        <x-slot:head><tr>
            <x-ui.th :sort="$sortable" key="payer" class="grow">Кто платит</x-ui.th>
            <x-ui.th class="hidden sm:table-cell">ТС</x-ui.th>
            <x-ui.th class="hidden sm:table-cell">Статус</x-ui.th>
            <x-ui.th :sort="$sortable" key="created" class="num col-detail-hide hidden sm:table-cell">Создано</x-ui.th>
            <x-ui.th :sort="$sortable" key="amount" class="num">Сумма</x-ui.th>
        </tr></x-slot:head>
        @foreach ($days as $day => $group)
            @if ($day !== '')<tr class="table-group"><th colspan="5"><span class="table-group-name">{{ $day }}</span></th></tr>@endif
            @foreach ($group as $l)
                @php
                    [$car, $number] = L::car($l);
                    [$state, $tone] = L::short($l);
                    $paid = $l->state === PayLinkState::Paid;
                @endphp
                <tr data-detail-key="{{ $l->id }}" data-search-row @class(['opacity-50' => $l->state === PayLinkState::Canceled])>
                    <td class="grow">
                        <x-ui.row-link :key="$l->id" :with="['tab' => 'links']"><span class="cell-title">{{ $l->payerName() }}</span></x-ui.row-link>
                        <span class="cell-sub sm:hidden">@if ($car){{ $car }}@if ($number)<span class="nums">, № {{ $number }}</span>@endif @else{{ L::who($l) }}@endif</span>
                    </td>
                    <td class="hidden sm:table-cell">@if ($car){{ $car }}@if ($number)<span class="nums text-ink-dim"> {{ $number }}</span>@endif @else<span class="text-ink-dim">не указано</span>@endif</td>
                    <td class="hidden sm:table-cell {{ $tone }}">{{ $state }}</td>
                    <td class="num cell-dim col-detail-hide hidden sm:table-cell nums">{{ $day !== '' ? $l->created_at->format('H:i') : $when($l->created_at) }}<span class="text-ink-dim">, {{ L::who($l) }}</span></td>
                    <td class="num">
                        <span class="cell-title nums {{ $paid ? 'text-open' : '' }}">{{ Money::nums($l->amount) }}</span>
                        <span class="cell-sub sm:hidden {{ $tone }}">{{ $state }}</span>
                    </td>
                </tr>
            @endforeach
        @endforeach
    </x-ui.table>
    @if (! $links instanceof \Illuminate\Support\Collection && $links->hasPages())<div class="mt-8"><x-ui.pager :of="$links"/></div>@endif
    @endif
</section>
@endif
