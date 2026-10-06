{{-- Отдать в гараж минуя подтверждение — как принятое гаражное (`GiveToGarage`, 06.10.2026): кому (пусто — взяли под
     себя, платим мы), кто платит поставщику, где машина сейчас (с чего начать вывоз) и кто её везёт. Шторка «···» редактора
     и карточка строки «Без цены» (цены продажи для гаража не нужно); prefix — свои id полей у каждой. --}}
@props(['offer', 'managers', 'prefix' => 'garage'])
@php
    $branch = $offer->garageBranch();
    $picked = $offer->pickedUp();
@endphp
<form method="post" action="/offers/{{ $offer->number }}/garage" {{ $attributes->class(['flex flex-col gap-4']) }}>
    @csrf
    <x-ui.field name="manager_id" id="{{ $prefix }}-manager" label="Кому" :options="$managers->pluck('name', 'id')" placeholder="Взяли под себя"/>
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
    @unless ($picked)
        {{-- Где машина — словами (04.10.2026: бывает, менеджер её уже вывез и она стоит у него). --}}
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Где машина</span>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Garage\Actions\GiveToGarage::WHERE as $key => $label)
                    <label class="choice"><input type="radio" name="where" value="{{ $key }}" @checked(old('where', 'owner') === $key)><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Везёт</span>
            <div class="flex flex-wrap gap-2">
                <label class="choice"><input type="radio" name="pickup" value="manager" @checked(old('pickup', 'manager') === 'manager')><span>Менеджер</span></label>
                <label class="choice"><input type="radio" name="pickup" value="us" @checked(old('pickup') === 'us')><span>Мы</span></label>
            </div>
        </div>
    @else
        <input type="hidden" name="where" value="arrived">
    @endunless
    <x-ui.button type="submit" variant="primary" block>Отдать в гараж</x-ui.button>
</form>
