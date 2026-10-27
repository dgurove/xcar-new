{{-- Строка вкладки «Оплата» в «Сделках» CRM (27.10.2026, владелец: «не понимаю, кто сколько должен заплатить и когда»): кто —
     заголовок группы (менеджер), здесь машина, вендор с убытком, что с оплатой («счёта нет: не указал покупателя», «счёт от
     24 окт», «сообщил об оплате — проверить»), сколько должен и срок: просрочено — красным, срок в три дня — оранжевым.
     На телефоне что с оплатой — под названием, срок — под суммой. Нажатие — карточка сделки. Столбцы — `DealController::table('pay')`. --}}
@props(['deal', 'debt', 'table'])
@php $offer = $deal->offer; @endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-cell.title :key="$deal->id" :offer="$offer"/>
        <span class="cell-sub sm:hidden {{ $debt->ours ? 'text-ink' : '' }}">{{ $debt->status }}</span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('status')
    <td class="{{ $col->classes() }} {{ $debt->ours ? '' : 'text-ink-muted' }}">{{ $debt->status }}</td>
        @break
        @case('owed')
    <td class="{{ $col->classes() }}">
        <span @class(['block', 'text-danger' => $debt->late()])>{{ $debt->amount() }}</span>
        <span class="cell-sub sm:hidden {{ $debt->dueTone() }}">{{ $debt->dueWord() }}</span>
    </td>
        @break
        @case('due')
    <td class="{{ $col->classes() }} whitespace-nowrap {{ $debt->dueTone() }}">{{ $debt->dueWord() }}</td>
        @break
        @endswitch
    @endforeach
</tr>
