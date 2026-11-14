{{-- «Отменить сделку» (12.10.2026, владелец: «спрашивает в черновики или в продажу, и если в продажу — кому показывать и до
     какого числа»; было — две разные кнопки и шторка с одними ценами, «очень неудобно»): одна шторка, сверху сегмент
     «В продажу | В черновики». В продажу (`ReturnToSale`): цены (подставлены нынешние, заявленная пусто — от закупочной),
     «Показ» — срок приёма и «Кому показывать» теми же полями, что «Показ» предложения (`x-offer.close-field`, `x-audience.field`);
     подтверждение менеджера остаётся ждать среди прочих. В черновики — состояние Draft: подтверждение отклоняется. У машины
     в гараже — только «Вернуть в продажу», без сегмента. Открывает событие resale:open (пункт «···» редактора, «Действий»
     карточки, красная строка карточки сделки и гаража) — шторка стоит вне меню: поповер прячет вложенное. Черновик на
     аукционе («Вернуть в продажу…», 13.10.2026) — как гараж, без сегмента и без второго вопроса: шторка и есть вопрос.
     Машина стоит у менеджера (21.10.2026, Иван Заикин: взял в гараж, привёз, чинить не стал — продают другому) — «Где стоит»:
     «Стоит у Иван З.» (`offers.held_by`) с городом и адресом осмотра — его, новый покупатель забирает у него. --}}
@props(['offer'])
@php
    use App\Support\Money;
    $n = $offer->number;
    $garage = $offer->state === \App\Offers\OfferState::Garage || \App\Garage\Car::where('offer_id', $offer->id)->exists();
    $isDeal = ! $garage && $offer->state === \App\Offers\OfferState::Sold;
    $auction = $offer->onAuction();
    $closeAt = old('bids_close_at');
    $holder = $offer->car_place === \App\Offers\CarPlace::Keeper ? $offer->standsWith() : null;
    // Вывоз идёт — не пропадает (12.11.2026, владелец: «чтобы пока был вывоз — она продавалась», `ReturnToSale`).
    $pickup = ! $holder && ($t = \App\Logistics\Transport::openFor($offer)) && ! $t->taken();
    $confirm = $garage
        ? 'ТС уйдёт из гаража менеджера, гаражная сделка отменится, неоплаченные счета аннулируются'
        : 'Сделка отменится, подтверждение менеджера останется среди подтверждений, неоплаченные счета сделки аннулируются';
@endphp
<div data-controller="sheet" data-action="resale:open@window->sheet#open" class="contents">
    <x-ui.sheet id="resale-{{ $n }}" :title="$isDeal ? 'Отменить сделку' : 'Вернуть в продажу'" tall>
        <div class="flex flex-col gap-4" @if ($isDeal) data-controller="reveal" @endif>
            @if ($isDeal)
                <x-ui.segment name="_cancel_way" :options="['sale' => 'В продажу', 'draft' => 'В черновики']" value="sale" pick/>
            @endif
            <div data-reveal-target="pane" data-reveal-key="sale">
                <form method="post" action="/offers/{{ $n }}/resale" class="flex flex-col gap-4" data-price-guard="whole" data-price-guard-offer="{{ $n }}" @unless ($auction) data-turbo-confirm="{{ $isDeal ? 'Отменить сделку и вернуть в продажу?' : 'Вернуть в продажу?' }}" data-turbo-confirm-text="{{ $confirm }}" data-turbo-confirm-label="Вернуть" @endunless>
                    @csrf @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
                    <div>
                        <h3 class="list-head">Цены</h3>
                        {{-- Поля — как в «Показе» и шторке публикации: подпись сверху, заявленная и продажная рядом (12.11.2026, владелец:
                             «почему поля по-разному сделаны, хотя смысл одинаковый»). Заявленная — числом, не серой подсказкой. --}}
                        <div class="grid grid-cols-2 gap-2">
                            <x-ui.field name="floor_price" :id="'resale-floor-'.$n" label="Закупочная, ₽" :value="old('floor_price', $offer->floor_price ? Money::nums($offer->floor_price) : null)" span="col-span-2"/>
                            <x-ui.field name="publish_price" :id="'resale-publish-'.$n" label="Заявленная, ₽" :value="old('publish_price', $offer->declaredPrice() ? Money::nums($offer->declaredPrice()) : null)"/>
                            <x-ui.field name="asking_price" :id="'resale-asking-'.$n" label="Цена продажи, ₽" :value="old('asking_price', $offer->asking_price ? Money::nums($offer->asking_price) : null)" required/>
                        </div>
                    </div>
                    @if ($pickup)
                        <x-ui.info><x-ui.info-row icon="cat-truck">Вывоз продолжается, куда везти — решит логистика</x-ui.info-row></x-ui.info>
                    @endif
                    @if ($holder)
                        <div>
                            <h3 class="list-head">Стоит у {{ $holder->shortName() }}</h3>
                            <input type="hidden" name="stays" value="1">
                            <div class="flex flex-col gap-2">
                                <x-ui.combobox name="settlement_id" label="Город" url="/reference/settlements" :value="old('settlement_id', $offer->settlement_id)" :text="$offer->settlement?->title()" :id="'resale-city-'.$n"/>
                                <x-ui.field name="inspection_address" :id="'resale-address-'.$n" label="Адрес" :value="old('inspection_address', $offer->inspection_address)"/>
                            </div>
                        </div>
                    @endif
                    <x-offer.sale-city :offer="$offer" :id="'resale-sale-city-'.$n" suggest/>
                    <div>
                        <h3 class="list-head">Показ</h3>
                        <div class="flex flex-col gap-4">
                            <x-offer.binding-field :offer="$offer" :id="'resale-binding-'.$n"/>
                            <x-offer.close-field :offer="$offer" :id="'resale-close-'.$n" :value="$closeAt"/>
                        </div>
                        <x-audience.field :value="$offer->audience_rules ? json_encode($offer->audience_rules) : ''" :rules="\App\Offers\AudienceRules::of($offer)" inline class="mt-4"/>
                    </div>
                    <x-ui.sheet-foot>Вернуть в продажу</x-ui.sheet-foot>
                </form>
            </div>
            @if ($isDeal)
                <div data-reveal-target="pane" data-reveal-key="draft" hidden>
                    <form method="post" action="/offers/{{ $n }}/state" data-price-guard="publish" data-price-guard-offer="{{ $n }}" data-turbo-confirm="Отменить сделку?" data-turbo-confirm-text="ТС уйдёт в черновики, подтверждение менеджера отклонится, неоплаченные счета сделки аннулируются" data-turbo-confirm-label="Отменить">
                        @csrf<input type="hidden" name="state" value="draft">
                        <x-ui.sheet-foot variant="danger">Отменить сделку, в черновики</x-ui.sheet-foot>
                    </form>
                </div>
            @endif
        </div>
    </x-ui.sheet>
</div>
