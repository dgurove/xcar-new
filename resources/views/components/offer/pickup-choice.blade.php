{{-- Кто забирает ТС, когда её отдают (`Give`, `AssignDealPickup`) — один кусок на сделку и гараж. Сделка: «Забирает
     автомобиль» — менеджер (исполнитель вывоза) или «Логистика» (как назначит админ); вывоз уже поручен другому — по
     умолчанию «Логистика», то есть как назначено. 19.10.2026, владелец («не должно быть автоматически, что менеджер
     забирает» — Lada Granta ушла Екатерине и пропала из логистики, а её хотели отдать Татьяне): у сделки по умолчанию
     всегда «Логистика», менеджер — только если вывоз уже поручен ему. У гаража «Везёт в гараж» по-прежнему менеджер. Гараж: ещё «Где ТС» (04.10.2026: бывает, менеджер её уже вывез — тогда
     подготовка сразу) и «Везёт в гараж»; вывоз у гаражной есть всегда. Забрали уже или ТС на нашей парковке — ничего. --}}
@props(['offer', 'garage' => false, 'manager' => null])
@php
    $t = \App\Logistics\Transport::openFor($offer);
    $already = $manager && $t?->executor_id === $manager->id;
    $byManager = $garage ? (! $t?->executor_id || $already) : $already;
    $pick = old('pickup', $byManager ? 'manager' : 'us');
    $shows = $garage ? ! $offer->pickedUp() && $offer->parkVehicle?->state !== \App\Park\VehicleState::Stored : $offer->pickupChoosable();
@endphp
@if ($shows)
    @if ($garage)
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Где ТС</span>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Offers\Giving::WHERE as $key => $label)
                    <label class="choice"><input type="radio" name="where" value="{{ $key }}" @checked(old('where', 'owner') === $key)><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
    @endif
    <div class="flex flex-col gap-1.5">
        <span class="field-label">{{ $garage ? 'Везёт в гараж' : 'Забирает автомобиль' }}</span>
        <div class="flex flex-wrap gap-2">
            <label class="choice"><input type="radio" name="pickup" value="manager" @checked($pick === 'manager')><span>{{ $manager?->shortName() ?? 'Менеджер' }}</span></label>
            <label class="choice"><input type="radio" name="pickup" value="us" @checked($pick === 'us')><span>Логистика</span></label>
        </div>
    </div>
@elseif ($garage)
    <input type="hidden" name="where" value="arrived">
@endif
