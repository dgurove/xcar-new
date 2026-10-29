{{-- Номер из таблицы Кати, которого в системе нет: призрачная строка. «Завести» — черновик с этим номером и сразу
     редактор; заведённый встаёт в группу «Черновики» сам. «Убрать» — номер из списка. Столбцы — те же, что у
     x-offer.table-row вкладки (`OfferController::table`): номер — в названии, действия — на месте цены, дата — своя. --}}
@props(['claim', 'table'])
<tr class="legacy-row" data-search-row id="legacy-{{ $claim->id }}" data-flip="c:{{ $claim->claim_ref_key }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}"><span class="cell-title text-ink-muted"><x-ui.copy-code :value="$claim->claim_ref"/></span></td>
        @break
        @case('price')
    <td class="{{ $col->classes() }}">
        <span class="inline-flex items-center gap-1">
            <form method="post" action="/offers/legacy/{{ $claim->id }}/create" data-turbo-frame="_top">@csrf<button class="pill pill-accent !min-h-6 !py-0 !px-3 text-sm">Завести</button></form>
            <form method="post" action="/offers/legacy/{{ $claim->id }}" data-turbo-confirm="Убрать {{ $claim->claim_ref }} из списка?" data-turbo-confirm-label="Убрать">@csrf @method('delete')<button class="btn btn-icon -my-2" aria-label="Убрать из списка"><x-ui.icon name="x" class="size-4"/></button></form>
        </span>
    </td>
        @break
        @case('when')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::cell($claim->created_at, time: true) }}</td>
        @break
        @default
    <td class="{{ $col->classes() }}"></td>
        @endswitch
    @endforeach
</tr>
