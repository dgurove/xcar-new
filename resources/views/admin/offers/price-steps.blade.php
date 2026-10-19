{{-- «Цены» в окошке строки (19.10.2026, начальник: «в закупочной была продажная, а в закупочной 0», поля «поменялись
     местами»; владелец: «к унификации»). Поля — один кусок с редактором (`fields/money`): всё подписано и на своих местах.
     Черновик — плашкой сверху окошка, по шагам: текущий шаг лаймовой рамкой и с фокусом, последним — действие шага: без цены
     «Сохранить и дальше» (`then=advance`: строка уходит, карточка — к следующей; пустое поле — просто дальше) и рядом «В
     гараж» / «Вывоз» (их формы на месте полей, «‹ Цены» — назад); оценённый — «Выставить в продажу» и «Отдать». Над
     кнопками — «Показ» строкой (`x-offer.showing-row`, шторка со всем показом). Остальное
     (`plain`) — разделом формы без кнопок. Метки — под блоком, не в нём. --}}
@php
    $form = 'detail-edit-'.$n;
    // Ошибка «В гараж» или «Вывоза» открывает их снова — если такая панель здесь есть.
    $way = ($way === 'garage' && $garage) || ($way === 'pickup' && $pickup) ? $way : 'rate';
    // Текущий шаг: нет закупочной — она, есть — цена продажи (админу); всё стоит — шагов нет, сохраняет «Сохранить изменения».
    $now = $floorFirst ? 'floor' : ($rate ? 'asking' : null);
    $plain ??= false;
@endphp
<section @class(['flex w-full flex-col gap-3', 'rounded-(--radius-l) bg-surface-2 p-3' => ! $plain]) data-controller="reveal">
    @if ($ways)
        <input type="radio" name="_way-{{ $n }}" value="rate" class="sr-only" id="way-rate-{{ $n }}" data-action="reveal#pick" @checked($way === 'rate')>
    @endif
    <div class="flex flex-col gap-2.5" data-reveal-target="pane" data-reveal-key="rate" @if ($way !== 'rate') hidden @endif>
        <div class="flex items-center gap-3">
            <h2 @class(['text-lg font-medium' => ! $plain, 'detail-section !mb-0' => $plain])>Цены</h2>
            <span class="ml-auto">@include('admin.offers.fields.vat-check', ['form' => $form])</span>
        </div>
        @include('admin.offers.fields.money', ['form' => $form, 'now' => $now, 'idp' => 'price-'.$n.'-', 'plate' => ! $plain])
        {{-- «Показ» строкой (срок приёма, кому, отметки) — нажатие открывает шторку со всем показом (`x-offer.showing-row`);
             у оценённого — решают перед «Выставить в продажу», поэтому прямо над ней. --}}
        @unless ($plain)<x-offer.showing-row :offer="$offer" :form="$form"/>@endunless
        {{-- Действие шага — последним: без цены — «Сохранить и дальше» и «В гараж» / «Вывоз»; оценённый — «Выставить в
             продажу» (шторка слотов `publish-sheet-slot`, вне формы, событием) и «Отдать» (`x-offer.give-sheet`). --}}
        @if ($publishButton ?? false)
            <div class="flex gap-2">
                <button type="button" class="btn btn-s btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="publish-{{ $n }}:open">Выставить в продажу</button>
                @if ($giveMenu ?? false)<button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="give-{{ $n }}:open">Отдать</button>@endif
            </div>
        @elseif ($now)
            <div class="flex gap-2">
                <button type="submit" form="{{ $form }}" name="then" value="advance" class="btn btn-s btn-accent min-w-0 flex-1">Сохранить и дальше</button>
                @if ($garage)<label class="btn btn-s btn-quiet shrink-0" for="way-garage-{{ $n }}">В гараж</label>@endif
                @if ($pickup)<label class="btn btn-s btn-quiet shrink-0" for="way-pickup-{{ $n }}">Вывоз</label>@endif
            </div>
        @endif
        {{-- Оценки менеджеров (11.10.2026) — когда уже отдали; и под закупочной: оценку дают и без неё (14.10.2026). --}}
        @if ($rate && ($offer->openEstimates->isNotEmpty() || $offer->declinedEstimates->isNotEmpty()))
            @include('admin.offers.estimates')
        @endif
        {{-- Ориентир из закупки Carcade: цены менеджеров и наша. --}}
        @if ($admin && $offer->purchaseCar)
            <div class="flex flex-col gap-1">
                <div class="text-sm font-medium">Закупка № {{ $offer->purchaseCar->purchase->number }}</div>
                @include('admin.offers.purchase-prices', ['car' => $offer->purchaseCar])
            </div>
        @endif
    </div>
    @if ($garage)
        <input type="radio" name="_way-{{ $n }}" value="garage" class="sr-only" id="way-garage-{{ $n }}" data-action="reveal#pick" @checked($way === 'garage')>
        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="garage" @unless ($way === 'garage') hidden @endunless>
            <label class="flex items-center gap-1 self-start text-sm text-accent-text" for="way-rate-{{ $n }}"><x-ui.icon name="chevron-left" class="size-4"/>Цены</label>
            <h2 class="text-lg font-medium">В гараж</h2>
            <x-offer.give-garage :offer="$offer" :prefix="'detail-'.$n" inline/>
        </div>
    @endif
    @if ($pickup)
        <input type="radio" name="_way-{{ $n }}" value="pickup" class="sr-only" id="way-pickup-{{ $n }}" data-action="reveal#pick" @checked($way === 'pickup')>
        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="pickup" @unless ($way === 'pickup') hidden @endunless>
            <label class="flex items-center gap-1 self-start text-sm text-accent-text" for="way-rate-{{ $n }}"><x-ui.icon name="chevron-left" class="size-4"/>Цены</label>
            <h2 class="text-lg font-medium">Вывоз</h2>
            <x-logistics.assign-form :transport="$offer->pickup()?->isOpen() ? $offer->pickup() : null" :offer="$offer" :action="'/offers/'.$n.'/pickup'" prefix="detail-pickup-{{ $n }}"/>
        </div>
    @endif
</section>
