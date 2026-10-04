{{-- Гараж минуя подтверждение: этап, с которого начать. «Ждёт страховую» — гаражная сделка по маршруту (нужен
     менеджер и кто платит поставщику), остальное — сразу: машина уже у нас или у менеджера. Шторка «···» редактора
     и карточка строки «Без цены» (цены продажи для гаража не нужно); prefix — свои id полей у каждой. --}}
@props(['offer', 'managers', 'prefix' => 'garage'])
@php
    $branch = $offer->garageBranch();
    // Этап — словами о том, где машина сейчас (04.10.2026: бывает, менеджер её уже вывез и она стоит у него).
    $where = [
        \App\Garage\CarState::Waiting->value => 'У страховой',
        \App\Garage\CarState::Delivery->value => 'Можно забирать',
        \App\Garage\CarState::Repair->value => 'Уже у менеджера',
        \App\Garage\CarState::Selling->value => 'Уже продаёт',
    ];
@endphp
<form method="post" action="/offers/{{ $offer->number }}/garage" {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    <div class="flex flex-wrap gap-2">
        @foreach ($where as $stage => $label)
            <label class="choice"><input type="radio" name="stage" value="{{ $stage }}" @checked(old('stage', 'waiting') === $stage) data-action="reveal#pick"><span>{{ $label }}</span></label>
        @endforeach
    </div>
    <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="waiting">
        <x-ui.field name="manager_id" id="{{ $prefix }}-manager-route" label="Кому" :options="$managers->pluck('name', 'id')" placeholder="Выберите менеджера" required/>
        @if ($branch)
            <div class="flex flex-col gap-1.5">
                <span class="field-label">Платит поставщику</span>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Garage\GaragePayer::cases() as $payer)
                        <label class="choice"><input type="radio" name="payer" value="{{ $payer->value }}" @checked(old('payer', 'us') === $payer->value)><span>{{ $payer->label() }}</span></label>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
    @foreach (['delivery', 'repair', 'selling'] as $key)
        <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="{{ $key }}" hidden>
            <x-ui.field name="manager_id" id="{{ $prefix }}-manager-{{ $key }}" label="Кому" :options="$managers->pluck('name', 'id')" placeholder="Взяли под себя"/>
            <x-ui.field name="cost" id="{{ $prefix }}-cost-{{ $key }}" label="Отдали за, ₽" :value="$offer->floor_price"/>
        </div>
    @endforeach
    <x-ui.button type="submit" variant="primary" block>Отдать в гараж</x-ui.button>
</form>
