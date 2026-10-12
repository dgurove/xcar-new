{{-- «Отменить сделку» (12.10.2026, владелец: «спрашивает в черновики или в продажу, и если в продажу — кому показывать и до
     какого числа»; было — две разные кнопки и шторка с одними ценами, «очень неудобно»): одна шторка, сверху сегмент
     «В продажу | В черновики». В продажу (`ReturnToSale`): цены (подставлены нынешние, заявленная пусто — от закупочной),
     «Показ» — срок приёма (по умолчанию через 3 дня в 17:00) и «Кому показывать» тем же куском, что в модуле «Показ»;
     подтверждение менеджера остаётся ждать среди прочих. В черновики — состояние Draft: подтверждение отклоняется. У машины
     в гараже — только «Вернуть в продажу», без сегмента. Открывает событие resale:open (пункт «···» редактора, «Действий»
     карточки, красная строка карточки сделки и гаража) — шторка стоит вне меню: поповер прячет вложенное. --}}
@props(['offer'])
@php
    use App\Support\Money;
    $n = $offer->number;
    $garage = $offer->state === \App\Offers\OfferState::Garage || \App\Garage\Car::where('offer_id', $offer->id)->exists();
    $isDeal = ! $garage && $offer->state === \App\Offers\OfferState::Sold;
    $closeAt = old('bids_close_at', \App\Offers\Slots::closeFor(now())->format('Y-m-d\TH:i'));
    $confirm = $garage
        ? 'ТС уйдёт из гаража менеджера, гаражная сделка отменится, неоплаченные счета аннулируются'
        : 'Сделка отменится, подтверждение менеджера останется среди подтверждений, неоплаченные счета сделки аннулируются';
@endphp
<div data-controller="sheet" data-action="resale:open@window->sheet#open" class="contents">
    <x-ui.sheet id="resale-{{ $n }}" :title="$isDeal ? 'Отменить сделку' : 'Вернуть в продажу'" tall :open="$errors->hasAny(['asking_price', 'floor_price', 'publish_price', 'bids_close_at'])">
        <div class="flex flex-col gap-4" @if ($isDeal) data-controller="reveal" @endif>
            @if ($isDeal)
                <div class="segment">
                    <label><input type="radio" name="_cancel_way" value="sale" checked data-action="reveal#pick"><span>В продажу</span></label>
                    <label><input type="radio" name="_cancel_way" value="draft" data-action="reveal#pick"><span>В черновики</span></label>
                </div>
            @endif
            <div data-reveal-target="pane" data-reveal-key="sale">
                <form method="post" action="/offers/{{ $n }}/resale" class="flex flex-col gap-4" data-turbo-confirm="{{ $isDeal ? 'Отменить сделку и вернуть в продажу?' : 'Вернуть в продажу?' }}" data-turbo-confirm-text="{{ $confirm }}" data-turbo-confirm-label="Вернуть">
                    @csrf
                    <div>
                        <h3 class="list-head">Цены</h3>
                        <div class="fields">
                            <x-ui.field name="floor_price" :id="'resale-floor-'.$n" label="Закупочная, ₽" :value="old('floor_price', $offer->floor_price ? Money::nums($offer->floor_price) : null)" data-controller="digits" data-action="input->digits#format" inputmode="decimal"/>
                            <x-ui.field name="publish_price" :id="'resale-publish-'.$n" label="Заявленная, ₽" :value="old('publish_price', $offer->publish_price ? Money::nums($offer->publish_price) : null)" :placeholder="$offer->floor_price ? Money::nums(\App\Offers\Offer::declaredFrom($offer->floor_price)) : null" data-controller="digits" data-action="input->digits#format" inputmode="decimal"/>
                            <x-ui.field name="asking_price" :id="'resale-asking-'.$n" label="Цена продажи, ₽" :value="old('asking_price', $offer->asking_price ? Money::nums($offer->asking_price) : null)" required data-controller="digits" data-action="input->digits#format" inputmode="decimal"/>
                        </div>
                    </div>
                    <div>
                        <h3 class="list-head">Показ</h3>
                        <div class="fields">
                            <x-ui.field name="bids_close_at" :id="'resale-close-'.$n" label="Приём подтверждений до" type="datetime-local" default-time="17:30" :value="$closeAt" required/>
                            <x-offer.audience-field :offer="$offer" key="resale"/>
                        </div>
                    </div>
                    <div class="sheet-foot"><x-ui.button block>Вернуть в продажу</x-ui.button></div>
                </form>
            </div>
            @if ($isDeal)
                <div data-reveal-target="pane" data-reveal-key="draft" hidden>
                    <form method="post" action="/offers/{{ $n }}/state" data-turbo-confirm="Отменить сделку?" data-turbo-confirm-text="ТС уйдёт в черновики, подтверждение менеджера отклонится, неоплаченные счета сделки аннулируются" data-turbo-confirm-label="Отменить">
                        @csrf<input type="hidden" name="state" value="draft">
                        <div class="sheet-foot"><x-ui.button block variant="danger">Отменить сделку, в черновики</x-ui.button></div>
                    </form>
                </div>
            @endif
        </div>
    </x-ui.sheet>
</div>
