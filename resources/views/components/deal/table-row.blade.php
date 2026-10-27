{{-- Строка таблицы сделок CRM — одной линией (13.10.2026, владелец: «таблица, а не эссе»): иконка типа ТС и название, на
     телефоне под ним логотип вендора с номером убытка (копируется) и срок шага; от 640 они своими столбцами —
     вендор с убытком, менеджер аватаром (имя и № — от 1536), этап обычным цветом, «что сейчас» коротким словом (`x-route.term`:
     рыжим — только наш ход). Поставщик ответил — как непрочитанное письмо: лаймовая точка и полужирное название (27.10.2026).
     Справа сумма, на телефоне под ней этап словом. Кто ждёт и что с письмом — фразой в карточке сделки рядом (x-ui.row-link).
     Столбцы — `DealController::table` (шапка `x-table.head` по тому же описанию). --}}
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
    [$word, $tone] = match (true) {
        $deal->isAuction() && $deal->state === DealState::Done => ['Продана на аукционе', 'text-accent-text'],
        ! $active => [$deal->state->label(), $deal->state === DealState::Done ? 'text-accent-text' : 'text-danger'],
        // Этап не красится: горит «что сейчас» рядом, и только когда ход наш (27.10.2026).
        (bool) $position => [$position->stage->block?->name ?? $position->stage->name, ''],
        default => [$offer->crmTab(), 'text-ink-muted'],
    };
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
             там он резался). Не влез — переносится. --}}
        <span class="cell-sub sm:hidden">@if ($active && $position)<x-route.term :position="$position"/> <span class="inline-block whitespace-nowrap text-ink-muted">{{ mb_lcfirst($word) }}</span>@else<span class="{{ $tone }}">{{ $word }}</span>@endif</span>
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
    <td class="{{ $col->classes() }}">@if ($active && $position)<x-route.term :position="$position"/>@endif</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">
        {{ $deal->isGarage() ? 'В гараж' : ($deal->amount === null ? '' : \App\Support\Money::rub($deal->amount)) }}
        {{-- Только «в минус» (27.10.2026, владелец: «+251 000» в строке ни к чему и сдвигает сумму). --}}
        @if (($deal->marginMark()['tone'] ?? null) === 'loss')<x-offer.margin :m="$deal->marginMark()" class="cell-sub max-sm:!ml-0"/>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
