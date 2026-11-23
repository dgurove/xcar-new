{{-- Строка поступления: плательщик, под ним состояние (привязано — галочкой), на телефоне ещё дата;
     назначение столбцом на широком экране, сумма справа. Нажатие — карточка. --}}
@props(['tx'])
@php use App\Support\Money; @endphp
<tr data-detail-key="{{ $tx->id }}" data-search-row id="tx-{{ $tx->id }}">
    <td class="grow">
        <x-ui.row-link :key="$tx->id"><span class="cell-title flex items-center gap-2"><x-ui.avatar :name="\App\Billing\Bank\Actions\MatchTransaction::isPayout($tx) ? 'ЮMoney' : ($tx->counterparty ?: '?')" :size="24"/><span class="min-w-0 truncate">{{ $tx->counterparty ?: ($tx->isIncoming() ? 'Плательщик не указан' : 'Получатель не указан') }}</span></span></x-ui.row-link>
        {{-- Под плательщиком — только состояние: привязано — галочкой, без «как и к чему» (23.11.2026, владелец: «в списке не надо писать,
             как привязал и к чему, просто галочку»; это — в окне операции); не привязано и «не наше» — словом. --}}
        <span class="cell-sub">
            @if ($tx->state === \App\Billing\Bank\Transaction::MATCHED || ($tx->deal_id && $tx->state !== \App\Billing\Bank\Transaction::IGNORED))
                <span class="text-open inline-flex items-center" title="Привязано" aria-label="Привязано"><x-ui.icon name="check" class="size-4"/></span>
            @else
                <span class="text-{{ $tx->tone() }}">{{ mb_strtolower($tx->stateLabel()) }}</span>
            @endif
            <span class="sm:hidden">{{ \App\Support\Ago::html($tx->booked_at, words: false) }}</span>
        </span>
    </td>
    {{-- Назначение режется по ширине столбца: длинное выдавливало дату и сумму за край (14.11.2026). --}}
    <td class="cell-dim col-detail-hide hidden lg:table-cell" style="max-width: 28rem"><span class="block truncate">{{ $tx->purpose }}</span></td>
    <td class="hidden sm:table-cell">{{ \App\Support\Ago::html($tx->booked_at, words: false) }}</td>
    <td class="num nums">{{ Money::nums($tx->amount, 2) }}</td>
</tr>
