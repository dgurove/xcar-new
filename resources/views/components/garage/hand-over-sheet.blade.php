{{-- «Передать…» гаражную машину другому держателю (19.11.2026, начальник: Chery «под себя» — в гараж Ананьеву;
     `HandOverGarage`): сделка та же, поставщику по-прежнему платим мы. Кому — «Покупателю» (покупатель с гаражом, его цена —
     счёт ПРАЙМ), «Менеджеру» (где менеджер в гараже может не платить поставщику сам, `Offer::garagePayerFixed`) или «Себе».
     Открывают пункт «⋯» и нажатие на плашку держателя событием garage-holder-{номер}:open. Поля спрятанного варианта reveal
     выключает — в запрос уходит только выбранное. --}}
@props(['offer', 'car'])
@php
    $n = $offer->number;
    $id = 'garage-holder-'.$n;
    $toManager = ! $offer->garagePayerFixed();
    $toBuyer = $offer->garageToBuyer() && once(fn () => \App\Users\User::garageBuyers()->exists());
    $options = array_filter(['buyer' => $toBuyer ? 'Покупателю' : null, 'manager' => $toManager ? 'Менеджеру' : null,
        'self' => $car->manager_id ? 'Себе' : null]);
    $whom = old('whom', $car->forBuyer() && $toBuyer ? 'buyer' : array_key_first($options));
    $current = $car->manager_id;
@endphp
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-ui.sheet :id="$id" title="Передать">
        <form method="post" action="/offers/{{ $n }}/garage-holder" class="flex flex-col gap-4" data-controller="reveal">
            @csrf
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="tag">{{ $offer->titleWithYear() }}</span>
                <span class="text-sm text-ink-muted">сейчас: {{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>
            </div>
            @error('holder')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
            @if (count($options) > 1)
                <div class="flex flex-col gap-1.5">
                    <span class="field-label">Кому</span>
                    <x-ui.segment name="whom" :options="$options" :value="$whom" pick/>
                </div>
            @else
                <input type="hidden" name="whom" value="{{ $whom }}">
            @endif
            @if ($toBuyer)
                <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="buyer" @if ($whom !== 'buyer') hidden @endif>
                    <x-people.pick name="manager_id" label="Покупатель" kind="garage-buyer" :people="\App\Support\People::garageBuyers()" title="Покупатель" placeholder="Кому передаём"
                        :value="$car->forBuyer() ? $current : null"/>
                    <x-ui.field name="buyer_price" label="Цена для покупателя, ₽" :value="old('buyer_price', $car->buyer_price ?? $offer->asking_price)" :id="$id.'-price'" required/>
                </div>
            @endif
            @if ($toManager)
                <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="manager" @if ($whom !== 'manager') hidden @endif>
                    <x-people.pick name="manager_id" label="Менеджер" kind="manager" :people="\App\Support\People::managers()" title="Менеджер" placeholder="Кому передаём"
                        :value="$car->forBuyer() ? null : $current"/>
                </div>
            @endif
            <x-ui.sheet-foot type="submit" variant="primary">Передать</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
