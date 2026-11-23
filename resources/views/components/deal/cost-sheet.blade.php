{{-- Закупочная в сделке ПРАЙМ / Р.О.С. — шторка `cost-{сделка}` (24.11.2026, владелец: «в „Оплатах“ редактировать можно всё —
     доведи до ума, чтобы в разных местах не выглядело по-разному»). Открывает строка «Закупочная» «Продажи», пока по счетам не
     платили; `PUT /work/deals/{id}/cost` → `UpdateDealMoney` (`setCost`): договор, счёт за ТС и деление в «Оплатах» — по новой.
     Закупочная предложения остаётся прежней. --}}
@props(['deal'])
@php
    use App\Support\Money;
    $id = 'cost-'.$deal->id;
    $cost = old('_sheet') === $id ? old('cost') : Money::nums((int) $deal->cost);
@endphp
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-ui.sheet :id="$id" title="Закупочная">
        <form method="post" action="/work/deals/{{ $deal->id }}/cost" class="flex flex-col gap-4">
            @csrf @method('put')
            <input type="hidden" name="_sheet" value="{{ $id }}">
            @if ($deal->amount !== null)
                <div class="list">
                    <div class="row sale-row"><span class="sale-row-label">Цена продажи</span><span class="sale-row-value nums">{{ Money::rub((int) $deal->amount) }}</span></div>
                </div>
            @endif
            <x-ui.field name="cost" label="Закупочная, ₽" :value="$cost" :id="$id.'-cost'" required/>
            <x-ui.sheet-foot type="submit">Сохранить</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
