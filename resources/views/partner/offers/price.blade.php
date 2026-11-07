{{-- «Цена» редактора поставщика (08.11.2026, владелец: редактор — «халтура»): на ПК — липкая панель справа, на телефоне —
     карточка под фото. Оценочная — крупным полем (пустое: у машины бывает заявленная стоимость парковки, её не показываем и
     не стираем); наценка — строкой «+10 %» по ступени, где лежит оценочная, диапазоны — под «Изменить» (их почти не трогают);
     закупочная — итогом крупно (считает сервер, `OfferRequest::payload`). Одна кнопка: вписана оценочная — «Отправить цену»
     (и дальше к следующей), нет — «Сохранить» (на месте). --}}
@php use App\Support\Money; @endphp
<section class="box partner-price-panel">
    <h2 class="box-title">Цена</h2>
    <label class="partner-value @error('value') is-invalid @enderror" for="partner-value">
        <span class="partner-value-label">Оценочная</span>
        <span class="partner-value-box">
            <input type="text" id="partner-value" name="value" inputmode="decimal" autocomplete="off" enterkeyhint="done" class="partner-value-input nums"
                data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value" value="{{ old('value') }}">
            <span class="partner-value-rub">₽</span>
        </span>
    </label>
    @error('value')<p class="field-error">{{ $message }}</p>@enderror

    <details class="partner-markup" @if ($errors->has('floor_rules') || $rules !== $default) open @endif>
        <summary>
            <span class="text-ink-muted">Наценка</span>
            <span class="nums" data-value-floor-target="rule">{{ count($rules) > 1 ? 'по диапазонам' : \App\Offers\Pricing::rulesLabel($rules) }}</span>
            <span class="partner-markup-edit">Изменить</span>
        </summary>
        <div class="pt-3">
            <x-offer.floor-rules :rules="$rules" :default="$default" id="partner-rules"/>
            @error('floor_rules')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </details>

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

    <button type="submit" form="partner-offer" class="btn btn-accent w-full max-md:hidden" data-value-floor-target="button" data-idle="Сохранить" data-ready="Отправить цену">Сохранить</button>
</section>
