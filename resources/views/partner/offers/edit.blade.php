{{-- Редактор черновика у поставщика (partner.xcar.ru; вид — 08.11.2026, владелец: «редактор — это пиздец халтура»). Сверху —
     название с годом, номер убытка и VIN (копируются), справа «3 из 57 ‹ ›» — пролистать очередь, не уходя в список.
     Слева — фото крупно (`partner.offers.photos`), под ними поля группами строк, как «Настройки» iOS (подпись слева, значение
     справа): «Транспортное средство», «Характеристики», «ПТС и СТС» (пустая — свёрнута), «Осмотр», документы. Справа на ПК —
     липкая «Цена» (`partner.offers.price`), на телефоне она сразу под фото, кнопка — плашкой снизу. Поля те же, что у
     модератора, но без вендора (он зашит), номера, источников, вариантов, подсказок, разбора текста и «Заполнить по VIN»:
     поставщик вписывает сам (`Partner\OfferRequest`). --}}
@php
    use App\Cars\CarFields;
    use App\Offers\Offer;
    use App\Support\Liters;
    $fields = \App\Http\Partner\OfferRequest::carFields();
    $raw = function (string $c) use ($offer) {
        $v = old(CarFields::name(Offer::class, $c), $offer->{CarFields::name(Offer::class, $c)});

        return $v instanceof \BackedEnum ? $v->value : $v;
    };
    $cols = fn (string $block) => array_values(array_filter(CarFields::BLOCKS[$block][1], fn ($c) => in_array(CarFields::name(Offer::class, $c), $fields, true)));
    // VIN — последним в «Транспортном средстве»: сначала то, по чему ТС узнают (марка, год, цвет).
    $car = [...array_diff($cols('car'), ['vin']), ...(in_array('vin', $cols('car'), true) ? ['vin'] : [])];
    $papers = $cols('papers');
    $papersEmpty = collect($papers)->every(fn ($c) => blank($raw($c))) && ! $errors->hasAny(array_map(fn ($c) => CarFields::name(Offer::class, $c), $papers));
    $title = $offer->brand_id ? $offer->titleWithYear() : 'Новое предложение';
@endphp
<x-ui.shell :title="$title" back="/offers">
    @if ($queue)
        <x-slot:actions class="!w-auto ml-auto max-md:hidden">@include('partner.offers.queue')</x-slot:actions>
    @endif

    {{-- Пересчёт цены — на обёртке: кнопка телефона (плашка снизу) вне формы, а подпись у неё та же. --}}
    <div class="contents" data-controller="value-floor" data-value-floor-rules-value="@json($rules)" data-action="floor-rules:change->value-floor#rules">
    <form method="post" action="/offers/{{ $offer->id }}" id="partner-offer" class="partner-edit">
        @csrf @method('put')
        <div class="partner-edit-head flex flex-wrap items-center gap-2">
            <x-ui.copy-code :value="$offer->claim_ref" class="chip"/>
            @if ($offer->vin)<x-ui.copy-code :value="$offer->vin" class="chip" done="VIN в буфере"/>@endif
            {{-- Телефон: место в очереди — в строке номера, а не строкой под длинным названием. --}}
            @if ($queue)<div class="ml-auto md:hidden">@include('partner.offers.queue')</div>@endif
        </div>

        <div class="partner-edit-photos">@include('partner.offers.photos', ['offer' => $offer])</div>

        <div class="partner-edit-price">@include('partner.offers.price', ['offer' => $offer])</div>

        {{-- Две колонки групп на широком ПК, каждая своей стопкой: свёрнутая «ПТС и СТС» не оставляет дыры рядом с «Осмотром». --}}
        <div class="partner-edit-fields">
            <div class="partner-edit-col">
                <section class="partner-group">
                    <h2 class="partner-group-title">Транспортное средство</h2>
                    <div class="fields fields-page">@include('partner.offers.fields', ['columns' => $car])</div>
                </section>
                <details class="partner-group partner-group-fold" @unless ($papersEmpty) open @endunless>
                    <summary class="partner-group-title">ПТС и СТС<span class="partner-group-add">Добавить</span></summary>
                    <div class="fields fields-page">@include('partner.offers.fields', ['columns' => $papers])</div>
                </details>
            </div>
            <div class="partner-edit-col">
                <section class="partner-group">
                    <h2 class="partner-group-title">Характеристики</h2>
                    <div class="fields fields-page">@include('partner.offers.fields', ['columns' => $cols('specs')])</div>
                </section>
                <section class="partner-group">
                    <h2 class="partner-group-title">Осмотр</h2>
                    <div class="fields fields-page">
                        <x-ui.combobox name="settlement_id" label="Город" url="/reference/settlements" :value="old('settlement_id', $offer->settlement_id)" :text="$offer->settlement?->title()"/>
                        <x-ui.field name="inspection_address" label="Адрес осмотра" :value="old('inspection_address', $offer->inspection_address)"/>
                        <x-ui.field name="description" label="Описание" type="textarea" :value="old('description', $offer->description)"/>
                    </div>
                </section>
                <section class="partner-group">
                    <h2 class="partner-group-title">Документы</h2>
                    <div class="box">@include('partner.offers.papers', ['offer' => $offer])</div>
                </section>
            </div>
        </div>
    </form>

    {{-- Свой черновик без цены — удалить (опечатка в номере): редко, поэтому внизу страницы тихой кнопкой. --}}
    @if ($offer->isDeletableBy(auth()->user()))
        <form method="post" action="/offers/{{ $offer->id }}" class="mt-8" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button variant="ghost" class="text-danger"><x-ui.icon name="trash" class="size-5"/> Удалить черновик</x-ui.button></form>
    @endif

    {{-- Телефон: «Сохранить» и «Отправить» — плашкой снизу; на ПК они в панели «Цена». --}}
    <x-ui.action-bar class="md:hidden">
        <button type="submit" form="partner-offer" class="btn btn-quiet min-w-0 flex-1">Сохранить</button>
        <button type="submit" form="partner-offer" name="then" value="send" class="btn btn-accent min-w-0 flex-1" data-value-floor-target="button" hidden>Отправить</button>
    </x-ui.action-bar>
    </div>
</x-ui.shell>
