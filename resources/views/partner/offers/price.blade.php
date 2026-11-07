{{-- «Цена» редактора поставщика (08.11.2026, владелец: редактор — «халтура»): на ПК — липкая панель справа, на телефоне —
     карточка под фото. Оценочная — крупным полем (пустое: у машины бывает заявленная стоимость парковки, её не показываем и
     не стираем); наценка — шкалой (`x-offer.floor-rules`: метка оценочной на ней, границы тянутся);
     закупочная — итогом крупно (считает сервер, `OfferRequest::payload`). «Сохранить» — на месте (цена у нас уже «Без
     продажной», у него остаётся); «Отправить» — сохранить и отдать нам, дальше следующая (владелец: «автоматически не
     отправлять»). --}}
@php use App\Support\Money; @endphp
<section class="box partner-price-panel">
    <h2 class="box-title">Цена</h2>
    {{-- Оценочная — строкой, как остальные поля (08.11.2026, владелец: «огроменные нахер поля»). Вписанная, но не отправленная
         — его число в поле. --}}
    <div class="fields">
        <div class="field @error('value') field-invalid @enderror">
            <label for="partner-value" class="field-label">Оценочная, ₽</label>
            <input type="text" id="partner-value" name="value" inputmode="decimal" autocomplete="off" enterkeyhint="done" class="field-input nums"
                data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value"
                value="{{ old('value', $offer->partner_price_at && $offer->value ? \App\Support\Money::nums((int) $offer->value) : '') }}">
            @error('value')<span class="field-error">{{ $message }}</span>@enderror
        </div>
    </div>

    {{-- Шкала наценки видна всегда: по ней понятно, откуда закупочная (08.11.2026, начальник: «шкалы красивые, динамичные»). --}}
    <x-offer.floor-rules :rules="$rules" :default="$default" id="partner-rules"/>
    @error('floor_rules')<p class="field-error">{{ $message }}</p>@enderror

    <div class="partner-floor">
        <span class="text-ink-muted">Закупочная</span>
        <span class="partner-floor-sum nums" data-value-floor-target="result"></span>
    </div>

    @if (\App\Offers\Binding::applies($offer))
        <div class="fields fields-page">
            <x-ui.field name="binding_until" label="Срок обязывающего" type="datetime-local" default-time="23:59"
                :value="old('binding_until', $offer->binding_until?->format('Y-m-d\TH:i'))"/>
        </div>
    @endif

    {{-- «Сохранить» — всё на месте; «Отправить» — сохранить и отдать нам (дальше следующая), только с оценочной. --}}
    <div class="flex flex-col gap-2 max-md:hidden">
        <button type="submit" form="partner-offer" name="then" value="send" class="btn btn-accent w-full" data-value-floor-target="button" hidden>Отправить</button>
        <button type="submit" form="partner-offer" class="btn btn-quiet w-full">Сохранить</button>
    </div>
</section>
