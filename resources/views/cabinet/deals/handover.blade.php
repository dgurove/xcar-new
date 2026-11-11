{{-- Получение автомобиля (`Offers\Handover`) внутри задачи сделки: «От XCar» и карточка `x-ui.info` — место с адресом под ним
     (нажатие — карта), когда, контакт с телефоном ссылкой. Что делать — заголовок задачи
     («Заберите автомобиль»), здесь только сведения. «Автомобиль забрал» вывоза без шага продажи (Альфа) ставит сам шаг. --}}
@php
    /** @var \App\Offers\Handover $handover */
    $map = $handover->address ? 'https://yandex.ru/maps/?text='.rawurlencode($handover->address) : null;
@endphp
<section @class(['handover', 'mt-4' => $caption ?? true]) aria-label="Получение автомобиля">
    {{-- Не «От поставщика» (03.11.2026, владелец: менеджеру не говорим даже, что пришёл ответ страховой): сведения дал XCar —
         поля шага, вывоз, парковка, показанное админом (`Disclosure`). Значками, без колонки подписей (11.11.2026). --}}
    @if ($caption ?? true)<p class="handover-cap">От XCar</p>@endif
    <x-ui.info>
        @if ($handover->where || $handover->address)
            <x-ui.info-row icon="map-pin" :href="$map" :sub="$handover->where ? $handover->address : null">{{ $handover->where ?: $handover->address }}</x-ui.info-row>
        @endif
        <x-ui.info-row icon="clock" class="nums">{{ $handover->dateLabel() }}</x-ui.info-row>
        {{-- Кто везёт — строкой той же карточки (`car-card`). --}}
        @if ($carrier ?? null)<x-ui.info-row icon="cat-truck">{{ $carrier }}</x-ui.info-row>@endif
        @if ($handover->name || $handover->phone)
            <div class="info-row">
                <x-ui.icon name="user" class="info-icon size-5"/>
                <span class="min-w-0 flex-1">
                    @if ($handover->name)<span class="block break-words">{{ $handover->name }}</span>@endif
                    @if ($handover->phone)<x-ui.tel :value="$handover->phone" class="block w-fit"/>@endif
                    @if ($handover->phone2)<x-ui.tel :value="$handover->phone2" class="block w-fit"/>@endif
                </span>
            </div>
        @endif
    </x-ui.info>
</section>
