{{-- Строка поступления: плательщик, под ним состояние цветным словом и счёт, к которому легло, на телефоне ещё дата;
     назначение столбцом на широком экране, сумма справа. Нажатие — окошко. --}}
@props(['tx'])
@php use App\Support\Money; $href = '/work/money/bank/'.$tx->id; @endphp
<tr data-detail-key="{{ $tx->id }}" data-search-row id="tx-{{ $tx->id }}">
    <td class="grow">
        <x-ui.row-link :key="$tx->id"><span class="cell-title flex items-center gap-2"><x-ui.avatar :name="\App\Billing\Bank\Actions\MatchTransaction::isPayout($tx) ? 'ЮMoney' : ($tx->counterparty ?: '?')" :size="24"/><span class="min-w-0 truncate">{{ $tx->counterparty ?: 'Плательщик не указан' }}</span></span></x-ui.row-link>
        <span class="cell-sub">
            <span class="text-{{ $tx->tone() }}">{{ mb_strtolower($tx->stateLabel()) }}</span>
            @if ($tx->invoice)<span>счёт {{ $tx->invoice->label() }}</span>@elseif ($tx->note)<span>{{ mb_strtolower($tx->note) }}</span>@endif
            <span class="sm:hidden">{{ $tx->booked_at->translatedFormat('j M') }}</span>
        </span>
    </td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell"><span class="block max-w-96 truncate">{{ $tx->purpose }}</span></td>
    <td class="hidden sm:table-cell">{{ $tx->booked_at->translatedFormat('j M') }}</td>
    <td class="num nums">{{ Money::nums($tx->amount, 2) }}</td>
</tr>
