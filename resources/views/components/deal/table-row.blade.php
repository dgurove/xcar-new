{{-- Строка таблицы сделок CRM — одной линией (13.10.2026, владелец: «таблица, а не эссе»): иконка типа ТС и название, на
     телефоне под ним логотип вендора с номером убытка (копируется) и срок шага; от 640 они своими столбцами —
     вендор с убытком, менеджер аватаром (имя и № — от 1536), этап обычным цветом, «что сейчас» коротким словом (`x-route.term`:
     рыжим — только наш ход). Поставщик ответил — как непрочитанное письмо: лаймовая точка и полужирное название (27.10.2026).
     Справа сумма, на телефоне под ней этап словом. Кто ждёт и что с письмом — фразой в карточке сделки рядом (x-ui.row-link).
     «Оплата» (27.10.2026, владелец: «нам надо понимать, кто сколько должен и какой срок») — остаток наших счетов и срок
     словом (`DealDebt`): просрочено — красным, срок в три дня — оранжевым, оплачено — зелёным; на телефоне срок — под суммой.
     Этап — `DealLine`, просроченная оплата — красным. Столбцы — `DealController::table` (шапка `x-table.head` по тому же описанию). --}}
@props(['deal', 'table'])
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->state === DealState::Active;
    // Срок обязывающего (19.10.2026): сделка уже загружена — без запросов на строку.
    $offer->setRelation('deal', $active ? $deal : null);
    $replied = $active && $position?->letterStatus() === 'replied';
    $burning = $active && \App\Offers\Binding::matters($offer) && \App\Offers\Binding::panics($offer);
    $debt = \App\Billing\DealDebt::of($deal);
    // Этап не красится: горит «что сейчас» рядом, и только когда ход наш (27.10.2026); красный — только просроченная оплата.
    // Срок оплаты словом — в своём столбце, у этапа его нет: «Оплата, просрочено 5 д» раздвигало таблицу шире экрана.
    $line = \App\Offers\DealLine::of($deal, $position);
    // Шаг оплаты с открытым счётом: срок — в «Оплате», у «что сейчас» часов нет.
    $payDue = $debt && ! $debt->paid() && $position?->stage->isPayStep();
    [$word, $tone] = [$line->word, $payDue && $debt->late() ? 'text-danger' : $line->tone];
@endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        {{-- Слот и @if — не вплотную (CLAUDE.md): условие внутри слота. --}}
        <x-cell.title :key="$deal->id" :offer="$offer" :strong="$replied">
            <x-slot:before>@if ($replied)<span class="card-tone card-tone--reply" aria-label="Поставщик ответил"></span>@endif</x-slot:before>
            @if ($burning)<x-offer.binding :offer="$offer" icon/>@endif
        </x-cell.title>
        {{-- На телефоне второй строкой — срок шага и этап (20.10.2026, как в «Вывозе»; 27.10.2026 — этап здесь, а не справа:
             там он резался). Не влез — переносится. Срок оплаты — под суммой, здесь этап без него. --}}
        <span class="cell-sub sm:hidden">@if ($active && $position)<x-route.term :position="$position"/> <span class="inline-block whitespace-nowrap text-ink-muted">{{ mb_lcfirst($position->stage->block?->name ?? $position->stage->name) }}</span>@else<span class="{{ $tone }}">{{ $word }}</span>@endif</span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">{{ $offer->number }}</td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}">@if ($deal->isAuction())<span class="text-ink-muted">Аукцион</span>@else<x-cell.person :user="$deal->buyer"/>@endif</td>
        @break
        @case('stage')
    <td class="{{ $col->classes() }}"><span class="block max-w-52 truncate {{ $tone }}">{{ $word }}</span></td>
        @break
        @case('term')
    <td class="{{ $col->classes() }}">@if ($active && $position)<x-route.term :position="$position" :clock="! $payDue" :waiting="! in_array($table->context('preset'), ['manager', 'supplier'], true)"/>@endif</td>
        @break
        @case('pay')
    <td class="{{ $col->classes() }}">
        @if ($debt && ! $debt->paid())<span @class(['block', 'text-danger' => $debt->late()])>{{ $debt->amount() }}</span>@endif
        @if ($debt)<span class="cell-sub {{ $debt->tone() }}">{{ $debt->word() }}</span>@endif
    </td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">
        {{ $deal->isGarage() ? 'В гараж' : ($deal->amount === null ? '' : \App\Support\Money::rub($deal->amount)) }}
        @if ($debt && ! $debt->paid())<span class="cell-sub sm:hidden {{ $debt->tone() }}">{{ $debt->word() }}</span>@endif
        {{-- Только «в минус» (27.10.2026, владелец: «+251 000» в строке ни к чему и сдвигает сумму). --}}
        @if (($deal->marginMark()['tone'] ?? null) === 'loss')<x-offer.margin :m="$deal->marginMark()" class="cell-sub max-sm:!ml-0"/>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
