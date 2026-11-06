{{-- Что сейчас с предложением и что с этим сделать — плашкой сверху окошка, тем же видом, что «Цены» черновика и «Приём»
     (19.10.2026, владелец: «непонятно, что от тебя хотят в этом окне», «к унификации»): кнопками, а не пунктами «Действий».
     Слот («Публикация») — когда выйдет, «Выставить сейчас», «Перенести» (шторка слотов), «Убрать из слота»; на аукционе —
     «Вернуть в продажу» (шторка `x-offer.resale-sheet`) и «В черновики»; архив — «Вернуть в черновики» и красным «Удалить
     навсегда»; «Без подтверждений» — цена, срок, кому и «Вернуть в продажу» / «В архив» (06.11.2026, ниже). На аукционе первой — «Продана на аукционе» (шторка `x-offer.auction-sale-sheet`, 20.10.2026). Этих пунктов в «Действиях» тогда нет. --}}
@php
    use App\Offers\{OfferState, Slots};
    $kind = match (true) {
        $offer->isScheduled() => 'slot',
        $offer->onAuction() => 'auction',
        \App\Offers\Actions\ReturnToSale::unsold($offer) => 'unsold',
        default => 'archive',
    };
@endphp
<section class="flex w-full flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3">
    @if ($kind === 'slot')
        <h2 class="text-base font-medium">Выйдет {{ Slots::phrase($offer->slot_at) }}</h2>
        <div class="flex flex-wrap gap-2" data-controller="sheet">
            <form method="post" action="/offers/{{ $n }}/state" data-price-guard="publish" data-price-guard-offer="{{ $n }}" class="flex basis-full">
                @csrf<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ Slots::NOW }}">
                <button class="btn btn-s btn-quiet flex-1">Выставить сейчас</button>
            </form>
            <button type="button" class="btn btn-s btn-quiet min-w-0 flex-1" data-action="sheet#open">Перенести</button>
            <form method="post" action="/offers/{{ $n }}/unschedule" class="flex min-w-0 flex-1">@csrf<button class="btn btn-s btn-quiet min-w-0 flex-1">Убрать из слота</button></form>
            <x-offer.publish-sheet :offer="$offer" :id="'slot-move-'.$n" :action="'/offers/'.$n.'/state'" state="open" title="Перенести" move/>
        </div>
    @elseif ($kind === 'unsold')
        {{-- «Без подтверждений» (06.11.2026, владелец: «удобно сразу быстро либо вернуть, либо архивировать», «стремиться, чтобы
             список был пустой»): что было — когда кончился приём и сколько раз выставляли; решение — одной плашкой: цена
             продажи с подсказками ниже (подтверждений не было — чаще всего дорого), срок приёма сегментом, кому — как в прошлый
             раз; «Вернуть в продажу» — сразу (`ReturnToSale`), «В архив» — без переспроса (из «Архива» вернут одной кнопкой).
             Строка уходит, карточка идёт к следующей (`OfferController::ADVANCE`). --}}
        @php
            $asking = (int) $offer->asking_price;
            $times = $offer->events()->where('type', \App\Offers\OfferEventType::StateChanged)->where('payload->to', OfferState::Open->value)->count();
            $cut = fn (float $k) => (int) (floor($asking * $k / 1000) * 1000);
            $hints = $asking ? array_filter(['−5 %' => $cut(.95), '−10 %' => $cut(.9), '−50 000' => $asking - 50000], fn ($v) => $v >= \App\Offers\Pricing::MIN) : [];
            $day = \App\Offers\Binding::clampClose($offer, Slots::closeFor(now(), 7));
        @endphp
        {{-- Плотно, как «Цены» черновика (06.11.2026, владелец: «зачем огромный отступ, шрифт огромный»): дата — в строке
             заголовка, поля — подписью сверху на светлой плашке, а не строкой настроек. --}}
        <div class="flex flex-wrap items-baseline gap-x-2">
            <h2 class="text-base font-medium">Без подтверждений</h2>
            <span class="nums text-sm text-ink-muted">приём закрыт {{ \App\Support\Ago::day($offer->intake_closed_at, true) }}@if ($times > 1), выставляли {{ $times }} {{ \App\Support\Plural::of($times, ['раз', 'раза', 'раз']) }}@endif</span>
        </div>
        <form method="post" action="/offers/{{ $n }}/resale" id="unsold-{{ $n }}" class="unsold-form flex flex-col gap-2" data-price-guard="whole" data-price-guard-offer="{{ $n }}">
            @csrf @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
            <div class="flex flex-col gap-1.5" data-put-scope>
                <div class="field @error('asking_price') field-invalid @enderror">
                    <div class="flex items-center gap-3">
                    <label for="unsold-price-{{ $n }}" class="field-label shrink-0 !mb-0">Цена продажи, ₽</label>
                    <input type="text" id="unsold-price-{{ $n }}" name="asking_price" inputmode="decimal" autocomplete="off" required class="field-input nums !bg-surface min-w-0 flex-1 text-right"
                        data-controller="digits" data-action="input->digits#format" value="{{ old('asking_price', $asking ? \App\Support\Money::nums($asking) : '') }}">
                    </div>
                    @error('asking_price')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                @if ($hints)
                    <div class="sheet-chips">
                        @foreach ($hints as $label => $value)
                            <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ \App\Support\Money::nums($value) }}" data-put-keep-param="true">{{ $label }}</button>
                        @endforeach
                        <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ \App\Support\Money::nums($asking) }}" data-put-keep-param="true">Как было</button>
                    </div>
                @endif
                @if ($offer->floor_price)
                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm text-ink-muted"><span class="nums">закупочная {{ \App\Support\Money::rub($offer->floor_price) }}</span><x-offer.margin :m="$offer->marginMark()"/></p>
                @endif
            </div>
            <div class="unsold-term flex flex-col gap-1.5">
                <span class="field-label">Срок показа</span>
                <x-ui.segment name="term" :options="['3' => '3 дня', '7' => 'Неделя', 'day' => 'До даты']" :value="old('term', '3')"/>
                <div class="unsold-day fields">
                    <x-ui.field name="day" :id="'unsold-day-'.$n" label="Показывать до" type="datetime-local" default-time="17:30" :value="old('day', $day->format('Y-m-d\TH:i'))" :min="now()->format('Y-m-d\TH:i')" :limit="\App\Offers\Binding::closeLimit($offer)"/>
                </div>
                @error('day')<span class="text-sm text-danger">{{ $message }}</span>@enderror
                @error('bids_close_at')<span class="text-sm text-danger">{{ $message }}</span>@enderror
            </div>
            <x-offer.audience-field :offer="$offer" key="unsold"/>
        </form>
        <div class="flex flex-wrap gap-2">
            <button form="unsold-{{ $n }}" class="btn btn-s btn-accent min-w-0 flex-1">Вернуть в продажу</button>
            <form method="post" action="/offers/{{ $n }}/state" class="flex">
                @csrf<input type="hidden" name="state" value="{{ OfferState::Archived->value }}">
                <button class="btn btn-s btn-quiet">В архив</button>
            </form>
        </div>
    @elseif ($kind === 'auction')
        <h2 class="text-base font-medium">На аукционе{{ $offer->auction_at ? ' с '.\App\Support\Ago::day($offer->auction_at) : '' }}</h2>
        <div class="flex flex-wrap gap-2">
            @if (\App\Offers\Actions\SellAtAuction::allows($offer, auth()->user()))
                <button type="button" class="btn btn-s btn-accent basis-full" data-controller="emit" data-action="emit#send" data-emit-event-param="auction-sale-{{ $n }}:open">Продана на аукционе</button>
            @endif
            <button type="button" class="btn btn-s btn-quiet min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="resale:open">Вернуть в продажу</button>
            <form method="post" action="/offers/{{ $n }}/auction" class="flex">@csrf @method('delete')<button class="btn btn-s btn-quiet">В черновики</button></form>
        </div>
    @else
        @php $declined = $offer->state === OfferState::Cancelled ? $offer->declinedDeal : null; @endphp
        @if ($declined)
            {{-- Отказ поставщика (28.10.2026): когда, кто отметил, менеджер сделки и причина словами. --}}
            @php $by = $offer->events()->where('type', \App\Offers\OfferEventType::SupplierDeclined)->with('user')->first(); @endphp
            <div>
                <h2 class="text-base font-medium text-danger">Поставщик отказал{{ $declined->closed_at ? ' '.\App\Support\Ago::day($declined->closed_at) : '' }}</h2>
                <p class="text-sm text-ink-muted">{{ collect([$declined->buyer ? 'сделка '.$declined->buyer->shortName() : null, $by?->user ? 'отметил '.$by->user->shortName() : null])->filter()->implode(', ') }}</p>
                @if ($declined->close_note)<p class="mt-2">{{ $declined->close_note }}</p>@endif
            </div>
        @else
            <h2 class="text-base font-medium">{{ $offer->state === OfferState::Cancelled ? 'Снято' : 'В архиве' }}</h2>
        @endif
        <div class="flex flex-wrap gap-2">
            @foreach ($transitions as [$next, $label])
                <form method="post" action="/offers/{{ $n }}/state" data-price-guard="publish" data-price-guard-offer="{{ $n }}" class="flex min-w-0 flex-1">
                    @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                    <button class="btn btn-s btn-quiet min-w-0 flex-1">{{ $label }}</button>
                </form>
            @endforeach
            @if ($purge)
                <form method="post" action="/offers/{{ $n }}/purge" class="flex" data-turbo-frame="_top" data-turbo-confirm="Удалить навсегда? Фото, документы, сделки, чаты и счета по нему удалятся без возврата" data-turbo-confirm-label="Удалить">
                    @csrf<button class="btn btn-s btn-danger">Удалить навсегда</button>
                </form>
            @endif
        </div>
    @endif
</section>
