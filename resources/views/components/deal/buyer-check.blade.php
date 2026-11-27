{{-- Проверка реквизитов покупателя перед отправкой (26.11.2026, владелец: «распознавание часто кривое, менеджеры отправляют как
     есть»; «по сути там сразу и проверка и отправка, „Всё верно“ может любой сотрудник»). Вместо «Отправить менеджеру» / «Отправить
     реквизиты» — «Проверить и …»: шторка с данными покупателя рядом со сканом паспорта (те же поля, что «Данные покупателя»),
     поправить можно тут же; «Всё верно, …» — сохранить (сотрудник вписал — проверено, `CheckBuyer`) и сразу отправить то, ради чего
     проверяли (`then`: `invoice:N`, `bills`, `contract`, `letter`, `DealContractController::afterCheck`). Проверять нечего
     (`DealContract::needsBuyerCheck`) — компонент не рисуется, кнопку рисует вызывающий. --}}
@props(['deal', 'then', 'label' => 'Отправить менеджеру', 'icon' => 'send'])
@php
    // Договор сделки — только прочитать: экран записей не заводит (`DealContract::for` создал бы пустой).
    $contract = $deal->contract?->loadMissing(['buyer.party']);
    $buyer = $contract?->buyer;
    $party = $buyer?->party;
    $id = 'buyer-check-'.$deal->id.'-'.\Illuminate\Support\Str::slug($then);
    $word = mb_lcfirst($label);
@endphp
@if ($party && $contract?->needsBuyerCheck() && auth()->user()?->canEditDeals())
    <div class="contents" data-controller="sheet">
        <button type="button" {{ $attributes->merge(['class' => 'btn btn-s gap-2'.(str_contains((string) $attributes->get('class'), 'btn-quiet') ? '' : ' btn-accent')]) }} data-action="sheet#open" aria-controls="{{ $id }}"><x-ui.icon name="check-circle" class="size-4 shrink-0"/>Проверить и {{ $word }}</button>
        <x-ui.sheet :id="$id" title="Проверьте реквизиты покупателя" :open="old('_sheet') === $id" tall>
            {{-- Карточка организации файлами — в самих полях (`x-billing.card-files` в `buyer-fields`, 27.11.2026). --}}
            <form method="post" action="/work/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="{{ $id }}-form">
                @csrf @method('put')
                <input type="hidden" name="_sheet" value="{{ $id }}">
                <input type="hidden" name="then" value="{{ $then }}">
                <x-billing.buyer-fields :party="$party" :key="$id" :scan="$id.'-form'" :fallback="$buyer->name"/>
                @error('buyer')<p class="field-error">{{ $message }}</p>@enderror
                <x-ui.sheet-foot>Всё верно, {{ $word }}</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif
