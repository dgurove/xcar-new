{{-- Кто вывозит ТС и куда (`AssignPickup`): «Мы» или менеджер; к менеджеру (когда вывозит он; машину в гараже — к её
     менеджеру, вариант с его именем), к нам — вне парковки, на парковку — с заявкой на эвакуацию. Карточка «Вывоз»
     редактора, карточка строки «Без цены» и «Работа → Вывоз»; prefix — свои id полей у каждой. --}}
@props(['offer', 'managers', 'prefix' => 'pickup'])
@php
    $who = old('evacuator_id', $offer->evacuator_id);
    // Машина в гараже стоит у своего менеджера, кто бы её ни вёз: вариант с его именем виден всегда и выбран сам.
    $holder = $offer->garageCar?->manager;
    $to = old('evacuation_to', $holder && ! $offer->pickedUp() ? 'keeper' : ($offer->evacuation_to ?? ($who ? 'keeper' : 'yard')));
@endphp
<form method="post" action="/offers/{{ $offer->number }}/pickup" {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    <x-ui.field name="evacuator_id" id="{{ $prefix }}-who" label="Кто вывозит" :options="$managers->pluck('name', 'id')" placeholder="Мы" :value="$who"
        data-action="change->reveal#toggle" data-reveal-key-param="keeper"/>
    <div class="flex flex-wrap gap-2">
        @foreach (\App\Offers\Destination::cases() as $d)
            <label class="choice" @if ($d === \App\Offers\Destination::Keeper && ! $holder) data-reveal-target="pane" data-reveal-key="keeper" @if (! $who) hidden @endif @endif>
                <input type="radio" name="evacuation_to" value="{{ $d->value }}" @checked($to === $d->value)><span>{{ $d === \App\Offers\Destination::Keeper && $holder ? 'К '.$holder->shortName() : $d->label() }}</span>
            </label>
        @endforeach
    </div>
    @error('evacuator_id')<p class="field-error">{{ $message }}</p>@enderror
    @error('evacuation_to')<p class="field-error">{{ $message }}</p>@enderror
    <x-ui.button type="submit" variant="primary" block>{{ $offer->position(\App\Workflow\Track::Service) ? 'Сохранить' : 'Нужен вывоз' }}</x-ui.button>
</form>
