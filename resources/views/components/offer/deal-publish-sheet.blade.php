{{-- «Опубликовать для других менеджеров» (12.11.2026, владелец: «она в сделке у этого менеджера, но начальник хочет опубликовать
     её для других менеджеров»; потом — «показ, волны и это окошко — одно и то же», «нельзя показывать всем, кроме»): машина
     остаётся в сделке (и гаражная, пока не привезли), другим менеджерам — обычное предложение до срока, их подтверждения —
     резервом («Отдать» в «Подтверждениях»). Срок и «Кому» — те же поля, что у «Показа» предложения (`x-offer.close-field`,
     `x-audience.field deal`). Идёт — та же шторка: поправить и «Снять публикацию». `PublishDeal`, `POST|DELETE
     /offers/{n}/deal-publication`; открывают пункт «⋯», строка «Показ» сделки и факт в шапке окна событием deal-publish:open.
     Сверху — заявленная и цена продажи (12.11.2026, начальник): цены предложения, их видят те, кому публикуем; сделку держат
     свои `deals.amount` и `deals.cost`, закупочная тут не правится. У гаражной цены продажи часто нет — без неё не публикуем. --}}
@props(['offer'])
@php
    use App\Offers\{AudienceRules, Binding, Slots};
    use App\Offers\Actions\PublishDeal;
    use App\Support\Money;
    $n = $offer->number;
    $on = $offer->showsDealAsOpen();
    $until = old('until', $on ? $offer->deal_show_until?->format('Y-m-d\TH:i') : Binding::clampClose($offer, Slots::closeFor(now()))->format('Y-m-d\TH:i'));
    $rules = AudienceRules::forDeal($offer);
@endphp
<div data-controller="sheet" data-action="deal-publish:open@window->sheet#open" class="contents">
    <x-ui.sheet id="deal-publish-{{ $n }}" :title="$on ? 'Публикация для других менеджеров' : 'Опубликовать для других менеджеров'">
        <form method="post" action="/offers/{{ $n }}/deal-publication" class="flex flex-col gap-4" id="deal-publish-form-{{ $n }}" data-price-guard="whole" data-price-guard-offer="{{ $n }}" @if ($on) data-price-guard-changed @endif>
            @csrf @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
            <div class="grid grid-cols-2 gap-2">
                <x-ui.field name="publish_price" label="Заявленная, ₽" :id="'deal-publish-declared-'.$n" :value="$offer->declaredPrice() ? Money::nums($offer->declaredPrice()) : null"/>
                <x-ui.field name="asking_price" label="Цена продажи, ₽" :id="'deal-publish-asking-'.$n" :value="$offer->asking_price ? Money::nums($offer->asking_price) : null" required/>
            </div>
            <x-offer.sale-city :offer="$offer" :id="'deal-publish-sale-city-'.$n"/>
            <x-offer.close-field :offer="$offer" name="until" :id="'deal-publish-until-'.$n" :value="$until"/>
            <x-audience.field :value="$offer->deal_audience_rules ? json_encode($offer->deal_audience_rules) : ''" :rules="$rules" inline deal/>
            @error('audience_rules')<p class="field-error">{{ $message }}</p>@enderror
        </form>
        <div class="sheet-foot flex flex-col gap-2">
            <button type="submit" form="deal-publish-form-{{ $n }}" class="btn btn-accent w-full">{{ $on ? 'Сохранить' : 'Опубликовать' }}</button>
            @if ($on)
                <form method="post" action="/offers/{{ $n }}/deal-publication" data-turbo-confirm="{{ PublishDeal::STOP_CONFIRM }}" data-turbo-confirm-text="{{ PublishDeal::STOP_TEXT }}" data-turbo-confirm-label="Снять">
                    @csrf @method('delete')<button type="submit" class="btn btn-quiet w-full">Снять публикацию</button>
                </form>
            @endif
        </div>
    </x-ui.sheet>
</div>
