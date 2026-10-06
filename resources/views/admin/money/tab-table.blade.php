{{-- Вкладка «Оплат» таблицей — вид по умолчанию (07.10.2026, владелец: «мне гораздо удобнее табличные», строки низкие,
     чтобы помещалось больше). Одна `x-ui.table` на вкладку, группы — липкие `.table-group`: «Проверить» (менеджер пишет,
     что оплатил), «Ждём» / «Выплатить», «Оплачено» / «Выплачено». На ПК строка в одну линию 36 px — каждый факт своим
     столбцом, второго этажа нет; на телефоне штатно: название в два этажа, факты под ним, справа сумма. «Сейчас» — что со
     счётом цветным словом, «Создано» — дата и время справа, как в предложениях. Заголовки сортируют (`x-ui.th`,
     `MoneyController::sorts`). Фразы — `MoneyRows`, строка открывает карточку рядом (`x-ui.row-link`). --}}
@php
    use App\Billing\PaymentSource; use App\Http\Admin\MoneyRows as R; use App\Support\Money;
    $paid = $s['paid'];
    $paidItems = $paid instanceof \Illuminate\Support\Collection ? $paid : collect($paid->items());
    $empty = $s['claims']->isEmpty() && $s['open']->isEmpty() && $paidItems->isEmpty();
    $created = 'num cell-dim col-detail-hide hidden sm:table-cell';
    // Столбцы вкладки: [заголовок, классы ячейки, поле сортировки].
    $cols = match ($key) {
        'links' => [['Кто платит', 'grow', 'payer'], ['За что', 'cell-dim hidden sm:table-cell', null], ['Сейчас', 'hidden sm:table-cell', null], ['Создано', $created, 'created'], ['Сумма', 'num', 'amount']],
        'owed' => [['Плательщик', 'grow', 'payer'], ['Менеджер', 'col-detail-hide hidden lg:table-cell', null], ['ТС', 'cell-dim hidden sm:table-cell', null], ['Счёт', 'cell-dim col-detail-hide hidden lg:table-cell', 'number'], ['Сейчас', 'hidden sm:table-cell', 'due'], ['Создано', $created, 'created'], ['Сумма', 'num', 'amount']],
        default => [['Менеджер', 'grow', 'payer'], ['ТС', 'cell-dim hidden sm:table-cell', null], ['Сейчас', 'hidden sm:table-cell', 'due'], ['Создано', $created, 'created'], ['Сумма', 'num', 'amount']],
    };
    $span = count($cols);
    $c = fn (int $n) => $cols[$n][1];
    $group = fn (string $name, int $count) => '<tr class="table-group"><th colspan="'.$span.'"><span class="table-group-name">'.e($name).' <span class="nums">'.$count.'</span></span></th></tr>';
    $when = fn ($i) => $i->created_at->translatedFormat($i->created_at->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i');
@endphp
@if (! ($titled && $empty))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS[$key] }}</h2>@endif
    @unless ($empty)
    <x-ui.table :id="'money-'.$key" class="mt-2">
        <x-slot:head><tr>@foreach ($cols as [$title, $class, $field])<x-ui.th :sort="$titled ? null : $sort" :key="$field" class="{{ str_replace('cell-dim', '', $class) }}">{{ $title }}</x-ui.th>@endforeach</tr></x-slot:head>

        {{-- Проверить: менеджер пишет, что оплатил — «Поступило» в карточке. --}}
        @if ($s['claims']->isNotEmpty())
            {!! $group('Проверить', $s['claims']->count()) !!}
            @foreach ($s['claims'] as $p)
                @php $i = $p->invoice; $now = R::who($i).' пишет, что '.($p->source === PaymentSource::Cash ? 'отдал наличные' : 'оплатил').' '.$p->paid_at->translatedFormat('j M'); @endphp
                <tr data-detail-key="{{ $i->id }}" data-search-row>
                    <td class="grow">
                        <x-ui.row-link :key="$i->id"><span class="cell-title">{{ $i->party->name }}</span></x-ui.row-link>
                        <span class="cell-sub sm:hidden"><span class="text-urgent">{{ $now }}</span>@if (R::what($i))<span>{{ R::what($i) }}</span>@endif</span>
                    </td>
                    <td class="{{ $c(1) }}">@if ($m = $i->manager())<x-ui.person :user="$m"/>@endif</td>
                    <td class="{{ $c(2) }}">{{ R::what($i) }}</td>
                    <td class="{{ $c(3) }}">{{ $i->label() }}</td>
                    <td class="{{ $c(4) }} text-urgent">{{ $now }}</td>
                    <td class="{{ $c(5) }} nums">{{ $when($i) }}</td>
                    <td class="num nums">{{ Money::nums($p->amount) }}</td>
                </tr>
            @endforeach
        @endif

        {{-- Ждём / Выплатить: открытые; по умолчанию сверху те, где по ссылке пытались и не вышло. --}}
        @if ($s['open']->isNotEmpty())
            {!! $group($key === 'payouts' ? 'Выплатить' : 'Ждём', $s['open']->count()) !!}
            @foreach ($s['open'] as $i)
                @php
                    $due = R::due($i);
                    $link = ($key === 'links' || $s['tried']->contains($i->id)) ? R::link($i) : null;
                    // Что сейчас — одно главное: не прошла по ссылке важнее срока; у выплаты без реквизитов — это.
                    [$now, $tone] = match (true) {
                        $key === 'payouts' && ! $i->party->payoutReady() => ['реквизитов нет', 'text-danger'],
                        $link && $key !== 'links' => $link,
                        $key === 'links' => $link ?? ['ждём оплату', ''],
                        (bool) $due => $due,
                        default => ['срок после договора', ''],
                    };
                    $partial = $i->isPartial() ? 'оплачено '.Money::rub($i->total - $i->remaining()).' из '.Money::rub($i->total) : null;
                @endphp
                <tr data-detail-key="{{ $i->id }}" data-search-row>
                    <td class="grow">
                        <x-ui.row-link :key="$i->id"><span class="cell-title">{{ $key === 'payouts' ? R::who($i) : $i->party->name }}</span></x-ui.row-link>
                        <span class="cell-sub sm:hidden">
                            <span class="{{ $tone }}">{{ $now }}</span>
                            @if (R::what($i))<span>{{ R::what($i) }}</span>@endif
                            @if ($key === 'payouts' && $due && $now === 'реквизитов нет')<span class="{{ $due[1] }}">{{ $due[0] }}</span>@endif
                        </span>
                    </td>
                    @if ($key === 'links')
                        <td class="{{ $c(1) }}">{{ R::what($i) }}</td>
                        <td class="{{ $c(2) }} {{ $tone }}">{{ $now }}</td>
                        <td class="{{ $c(3) }} nums">{{ $when($i) }}</td>
                    @elseif ($key === 'owed')
                        <td class="{{ $c(1) }}">@if ($m = R::via($i))<x-ui.person :user="$m"/>@endif</td>
                        <td class="{{ $c(2) }}">{{ R::what($i) }}</td>
                        <td class="{{ $c(3) }}">{{ $i->label() }}</td>
                        <td class="{{ $c(4) }} {{ $tone }}">{{ $now }}@if ($partial)<span class="text-ink-muted">, {{ $partial }}</span>@endif</td>
                        <td class="{{ $c(5) }} nums">{{ $when($i) }}</td>
                    @else
                        <td class="{{ $c(1) }}">{{ R::what($i) }}</td>
                        <td class="{{ $c(2) }} {{ $tone }}">{{ $now }}@if ($due && $now === 'реквизитов нет')<span class="{{ $due[1] }}">, {{ $due[0] }}</span>@endif</td>
                        <td class="{{ $c(3) }} nums">{{ $when($i) }}</td>
                    @endif
                    <td class="num nums">{{ Money::nums($i->remaining()) }}</td>
                </tr>
            @endforeach
        @endif

        {{-- Оплачено / Выплачено: по умолчанию свежие сверху, как заплатили. --}}
        @if ($paidItems->isNotEmpty())
            {!! $group($key === 'payouts' ? 'Выплачено' : 'Оплачено', $paid instanceof \Illuminate\Support\Collection ? $paidItems->count() : $paid->total()) !!}
            @foreach ($paidItems as $i)
                @php $now = $key === 'payouts' ? R::paidOut($i) : R::how($i, $attempts); @endphp
                <tr data-detail-key="{{ $i->id }}" data-search-row>
                    <td class="grow">
                        <x-ui.row-link :key="$i->id"><span class="cell-title">{{ $key === 'payouts' ? R::who($i) : $i->party->name }}</span></x-ui.row-link>
                        <span class="cell-sub sm:hidden"><span>{{ $now }}</span>@if (R::what($i))<span>{{ R::what($i) }}</span>@endif</span>
                    </td>
                    @if ($key === 'links')
                        <td class="{{ $c(1) }}">{{ R::what($i) }}</td>
                        <td class="{{ $c(2) }} text-ink-muted">{{ $now }}</td>
                        <td class="{{ $c(3) }} nums">{{ $when($i) }}</td>
                    @elseif ($key === 'owed')
                        <td class="{{ $c(1) }}">@if ($m = R::via($i))<x-ui.person :user="$m"/>@endif</td>
                        <td class="{{ $c(2) }}">{{ R::what($i) }}</td>
                        <td class="{{ $c(3) }}">{{ $i->label() }}</td>
                        <td class="{{ $c(4) }} text-ink-muted">{{ $now }}</td>
                        <td class="{{ $c(5) }} nums">{{ $when($i) }}</td>
                    @else
                        <td class="{{ $c(1) }}">{{ R::what($i) }}</td>
                        <td class="{{ $c(2) }} text-ink-muted">{{ $now }}</td>
                        <td class="{{ $c(3) }} nums">{{ $when($i) }}</td>
                    @endif
                    <td class="num nums text-open">{{ Money::nums($i->total) }}</td>
                </tr>
            @endforeach
        @endif
    </x-ui.table>
    @if (! $paid instanceof \Illuminate\Support\Collection && $paid->hasPages())<div class="mt-8"><x-ui.pager :of="$paid"/></div>@endif
    @endunless
</section>
@endif
