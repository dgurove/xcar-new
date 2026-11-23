{{-- Взаимозачёт со страховой — шторка `offset-{сделка}` (23.11.2026, владелец: «не понимаю, как настраивать — строка
     некликабельная, меню никакого нет»; выбрал вариант «А» — вводится сумма собственнику). ДКП собственнику: Т-Страхование отдаёт
     машину дешевле закупочной («предлагаем забрать за 750 000»), разница с закупочной гасится взаимозачётом (`Deal::offset`).
     Поле — «Собственнику по ДКП», взаимозачёт считается на лету (`offset_controller`); «Без взаимозачёта» — собственнику закупочная.
     `PUT /work/deals/{id}/owner-price` → `UpdateDealMoney` (подбор и его счёт встают заново). Открывают строки «Собственнику» и
     «Взаимозачёт со страховой» «Продажи» и пункт «⋯», пока взаимозачёта нет. С 24.11.2026 и «страховой напрямую» (владелец:
     «редактировать можно всё»): сумма поставщику — та же `owner_price`, разница с закупочной — тот же взаимозачёт. --}}
@props(['deal'])
@php
    use App\Support\Money;
    $id = 'offset-'.$deal->id;
    $cost = (int) $deal->cost;
    $owner = old('_sheet') === $id ? old('owner_price') : Money::nums((int) $deal->ownerPrice());
    $dkp = $deal->schemeOf() === \App\Offers\DealScheme::OwnerDkp;
@endphp
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-ui.sheet :id="$id" :title="$dkp ? 'Взаимозачёт со страховой' : 'Сумма поставщику'">
        <form method="post" action="/work/deals/{{ $deal->id }}/owner-price" class="flex flex-col gap-4" data-controller="offset" data-offset-cost-value="{{ $cost }}" data-action="input->offset#calc">
            @csrf @method('put')
            <input type="hidden" name="_sheet" value="{{ $id }}">
            <div class="list">
                <div class="row sale-row"><span class="sale-row-label">Закупочная</span><span class="sale-row-value nums">{{ Money::rub($cost) }}</span></div>
            </div>
            <x-ui.field name="owner_price" :label="$dkp ? 'Собственнику по ДКП, ₽' : 'Поставщику, ₽'" :value="$owner" :id="$id.'-owner'" required/>
            <div class="list">
                <div class="row sale-row"><span class="sale-row-label">Взаимозачёт</span><span class="sale-row-value nums" data-offset-target="out">{{ Money::rub(max(0, $deal->offset())) }}</span></div>
            </div>
            <button type="submit" name="none" value="1" formnovalidate class="btn btn-s btn-quiet self-start">Без взаимозачёта</button>
            <x-ui.sheet-foot type="submit">Сохранить</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
