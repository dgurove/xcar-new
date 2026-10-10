{{-- Вывоз ТС (`AssignPickup`), с 10.10.2026 — развилкой: «Везёт сам менеджер» (как было: кто из менеджеров и куда — к
     нему или к нам) или «Логистика» — перевозка в logistics.xcar.ru (`x-logistics.fields`: внутри региона или между,
     партнёр, наш эвакуатор, перевозчик; откуда, куда, кто принимает). Машину в гараже везут к её менеджеру, кто бы ни вёз:
     вариант с его именем виден всегда и выбран сам. Карточка «Вывоз» редактора и карточка строки «Без цены»; prefix —
     свои id полей у каждой. --}}
@props(['offer', 'managers', 'prefix' => 'pickup'])
@php
    use App\Offers\Destination;
    $transport = \App\Logistics\Transport::openFor($offer);
    $mode = old('mode', $offer->evacuator_id ? 'self' : 'logistics');
    $who = old('evacuator_id', $offer->evacuator_id);
    $holder = $offer->garageCar?->manager;
    $to = old('evacuation_to', $holder && ! $offer->pickedUp() ? 'keeper' : ($offer->evacuation_to ?? ($who ? 'keeper' : 'ours')));
@endphp
<form method="post" action="/offers/{{ $offer->number }}/pickup" {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    <div class="flex flex-wrap gap-2">
        <label class="choice"><input type="radio" name="mode" value="self" @checked($mode === 'self') data-action="reveal#pick"><span>Везёт сам менеджер</span></label>
        <label class="choice"><input type="radio" name="mode" value="logistics" @checked($mode === 'logistics') data-action="reveal#pick"><span>Логистика</span></label>
    </div>

    <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="self" @if ($mode !== 'self') hidden @endif>
        <x-ui.field name="evacuator_id" id="{{ $prefix }}-who" label="Менеджер" :options="$managers->pluck('name', 'id')" placeholder="Выберите" :value="$who"/>
        @unless ($holder)
            <div class="flex flex-wrap gap-2">
                @foreach (Destination::cases() as $d)
                    <label class="choice"><input type="radio" name="evacuation_to" value="{{ $d->value }}" @checked($to === $d->value)><span>{{ $d->label() }}</span></label>
                @endforeach
            </div>
        @endunless
    </div>

    <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="logistics" @if ($mode !== 'logistics') hidden @endif>
        <x-logistics.fields :transport="$transport" :car="$offer" :prefix="$prefix.'-t'"/>
        {{-- Без менеджера машина едет к нам; к менеджеру — только гаражная, к её держателю (радио ниже). --}}
        @unless ($holder)<input type="hidden" name="evacuation_to" value="ours">@endunless
    </div>

    @if ($holder)
        <div class="flex flex-wrap gap-2">
            @foreach (Destination::cases() as $d)
                <label class="choice"><input type="radio" name="evacuation_to" value="{{ $d->value }}" @checked($to === $d->value)><span>{{ $d === Destination::Keeper ? $d->label().', '.$holder->shortName() : $d->label() }}</span></label>
            @endforeach
        </div>
    @endif
    @error('mode')<p class="field-error">{{ $message }}</p>@enderror
    @error('evacuator_id')<p class="field-error">{{ $message }}</p>@enderror
    @error('evacuation_to')<p class="field-error">{{ $message }}</p>@enderror
    <x-ui.button type="submit" variant="primary" block>{{ $offer->position(\App\Workflow\Track::Service) ? 'Сохранить' : 'Нужен вывоз' }}</x-ui.button>
</form>
