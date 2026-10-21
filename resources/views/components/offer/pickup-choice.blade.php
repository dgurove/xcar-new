{{-- Кто забирает ТС, когда её отдают (`Give`, `AssignDealPickup`) — один кусок на сделку и гараж. Сделка: «Забирает
     автомобиль» — менеджер (исполнитель вывоза) или «Логистика» (как назначит админ); вывоз уже поручен другому — по
     умолчанию «Логистика», то есть как назначено. 19.10.2026, владелец («не должно быть автоматически, что менеджер
     забирает» — Lada Granta ушла Екатерине и пропала из логистики, а её хотели отдать Татьяне): у сделки по умолчанию
     всегда «Логистика», менеджер — только если вывоз уже поручен ему. У гаража «Везёт в гараж» по-прежнему менеджер. Гараж: ещё «Где ТС» (04.10.2026: бывает, менеджер её уже вывез — тогда
     подготовка сразу) и «Везёт в гараж»; вывоз у гаражной есть всегда. Забрали уже или ТС на нашей парковке — ничего.
     21.10.2026, владелец: «знаю, к кому повезут и откуда, а кто повезёт — ещё нет» — первым «Не определено» (`later`),
     у сделки оно и по умолчанию; у гаража по умолчанию по-прежнему менеджер. --}}
@props(['offer', 'garage' => false, 'manager' => null])
@php
    $t = \App\Logistics\Transport::openFor($offer);
    $already = $manager && $t?->executor_id === $manager->id;
    $byManager = $garage ? (! $t?->executor_id || $already) : $already;
    $pick = old('pickup', $byManager ? 'manager' : ($garage ? 'us' : 'later'));
    $shows = $garage ? ! $offer->pickedUp() && $offer->parkVehicle?->state !== \App\Park\VehicleState::Stored : $offer->pickupChoosable();
@endphp
@if ($shows)
    @if ($garage)
        {{-- Гараж «Себе» (`.give-garage` с «Кому» = «Себе», 19.10.2026): «Уже у нас», а «Везёт в гараж» нет — только логистика. --}}
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Где ТС</span>
            <div class="flex flex-wrap gap-2">
                <label class="choice"><input type="radio" name="where" value="owner" @checked(old('where', 'owner') === 'owner')><span>У страховой</span></label>
                <label class="choice"><input type="radio" name="where" value="arrived" @checked(old('where') === 'arrived')><span><span class="give-to-manager">{{ $manager?->holdsAsBuyer() ? 'Уже у покупателя' : 'Уже у менеджера' }}</span><span class="give-to-buyer">Уже у покупателя</span><span class="give-to-self">Уже у нас</span></span></label>
            </div>
        </div>
        <div class="give-carry">
            <div class="flex flex-col gap-1.5">
                <span class="field-label">Везёт в гараж</span>
                <div class="flex flex-wrap gap-2">
                    <label class="choice"><input type="radio" name="pickup" value="later" @checked($pick === 'later')><span>Не определено</span></label>
                    <label class="choice"><input type="radio" name="pickup" value="manager" @checked($pick === 'manager')><span>@if ($manager){{ $manager->shortName() }} сам@else<span class="give-to-manager">Менеджер сам</span><span class="give-to-buyer">Покупатель сам</span>@endif</span></label>
                    <label class="choice"><input type="radio" name="pickup" value="us" @checked($pick === 'us')><span>Логистика</span></label>
                </div>
            </div>
        </div>
    @else
    <div class="flex flex-col gap-1.5">
        <span class="field-label">Забирает автомобиль</span>
        <div class="flex flex-wrap gap-2">
            <label class="choice"><input type="radio" name="pickup" value="later" @checked($pick === 'later')><span>Не определено</span></label>
            <label class="choice"><input type="radio" name="pickup" value="manager" @checked($pick === 'manager')><span>{{ $manager?->shortName() ?? 'Менеджер' }}</span></label>
            <label class="choice"><input type="radio" name="pickup" value="us" @checked($pick === 'us')><span>Логистика</span></label>
        </div>
    </div>
    @endif
@elseif ($garage)
    <input type="hidden" name="where" value="arrived">
@endif
