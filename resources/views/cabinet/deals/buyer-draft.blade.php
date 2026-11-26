{{-- «Новый покупатель» после плитки вида (21.11.2026, владелец: брошенные «Новый покупатель» в покупателях — «тупость», заводить
     только при сохранении): поля и скан паспорта черновика у договора (`DealContract::draftParty`, контрагент без аккаунта).
     «Сохранить» заводит покупателя менеджера и ставит его в договор (`SaveDealContract`, `buyer_id = draft`); закрыли, не
     сохранив, — покупателя нет, черновик ждёт: строка «Новый покупатель» в выборе (`cabinet.deals.buyer-picker`) открывает его
     снова. Открывает флеш `open_sheet` (плитка) и событие `deal-buyer-new:open`. --}}
@php
    $draft = \App\Offers\DealContract::for($deal)->draftParty;
    $draftErrors = old('buyer_id') === 'draft' && collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'buyer'));
@endphp
@if ($draft && $deal->holderEditable())
    <div class="contents" data-controller="sheet" data-action="deal-buyer-new:open@window->sheet#open">
        <x-ui.sheet id="deal-buyer-new" title="Новый покупатель" :open="$draftErrors || session('open_sheet') === 'deal-buyer-new'" tall>
            <form method="post" action="/deals/{{ $deal->id }}/contract" class="flex flex-col gap-3" id="deal-buyer-new-form">
                @csrf @method('put')
                <input type="hidden" name="buyer_id" value="draft">
                <x-billing.buyer-fields :party="$draft" key="buyer-new" scan="deal-buyer-new-form"/>
                @error('buyer_id')<p class="field-error">{{ $message }}</p>@enderror
                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif
