{{-- Номер из таблицы Кати, которого в системе нет: призрачная строка. «Завести» — черновик с этим номером и сразу
     редактор; заведённый встаёт в группу «Черновики» сам. «Убрать» — номер из списка. Столбцы те же, что у
     x-offer.table-row вкладки (OfferController::columns), пустые — пусто. --}}
@props(['claim', 'cols'])
@php $has = fn (string $k) => in_array($k, $cols, true); @endphp
<tr class="legacy-row" data-search-row>
    <td class="grow">
        <span class="cell-title text-ink-muted"><x-ui.copy-code :value="$claim->claim_ref"/></span>
    </td>
    @if ($has('vendor'))<td class="hidden sm:table-cell"></td>@endif
    {{-- № — у незаведённого номера нет; столбец есть всегда (`table-head`). --}}
    <td class="col-detail-hide hidden sm:table-cell"></td>
    @if ($has('city'))<td class="col-detail-hide hidden sm:table-cell"></td>@endif
    <td class="hidden sm:table-cell"></td>
    @if ($has('floor'))<td class="col-detail-hide hidden sm:table-cell"></td>@endif
    <td class="num">
        <span class="inline-flex items-center gap-1">
            <form method="post" action="/offers/legacy/{{ $claim->id }}/create" data-turbo-frame="_top">@csrf<button class="pill pill-accent !min-h-6 !py-0 !px-3 text-sm">Завести</button></form>
            <form method="post" action="/offers/legacy/{{ $claim->id }}" data-turbo-confirm="Убрать {{ $claim->claim_ref }} из списка?" data-turbo-confirm-label="Убрать">@csrf @method('delete')<button class="btn btn-icon -my-2" aria-label="Убрать из списка"><x-ui.icon name="x" class="size-4"/></button></form>
        </span>
    </td>
    @if ($has('created'))<td class="num nums col-detail-hide hidden whitespace-nowrap !text-xs !text-ink-dim sm:table-cell">{{ $claim->created_at?->translatedFormat($claim->created_at->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i') }}</td>@endif
</tr>
