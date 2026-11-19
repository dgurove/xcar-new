{{-- «Передать…» машину из гаража (19.11.2026): «В гараж» — другому держателю в той же сделке (`HandOverGarage`): «Покупателю»
     (покупатель с гаражом, его цена — счёт ПРАЙМ), «Менеджеру» или «Себе»; «В сделку» — обычной сделкой менеджеру, в том числе
     тому же (`GarageToDeal`): форма «Отдать → В сделку», закупочная — гаражной с нашими расходами. Открывают пункт «⋯» и
     нажатие на плашку держателя событием garage-holder-{номер}:open. Поля спрятанного варианта reveal выключает. --}}
@props(['offer', 'car'])
@php
    use App\Garage\Actions\GarageToDeal;
    use App\Support\Money;
    $n = $offer->number;
    $id = 'garage-holder-'.$n;
    $toBuyer = $offer->garageToBuyer() && once(fn () => \App\Users\User::garageBuyers()->exists());
    $options = array_filter(['buyer' => $toBuyer ? 'Покупателю' : null, 'manager' => 'Менеджеру', 'self' => $car->manager_id ? 'Себе' : null]);
    $whom = old('whom', $car->forBuyer() && $toBuyer ? 'buyer' : 'manager');
    $dealBlocked = GarageToDeal::blocked($car);
    $cost = GarageToDeal::cost($car);
    $ours = $car->spent(\App\Garage\Payer::Xcar);
    $way = old('to') === 'deal' ? 'deal' : 'garage';
@endphp
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-ui.sheet :id="$id" title="Передать" tall>
        <div class="mb-4 flex flex-wrap items-center gap-1.5">
            <span class="tag">{{ $offer->titleWithYear() }}</span>
            <span class="text-sm text-ink-muted">сейчас: {{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>
        </div>
        <div class="flex flex-col gap-4" data-controller="reveal">
            <x-ui.segment name="_way" :options="['garage' => 'В гараж', 'deal' => 'В сделку']" :value="$way" pick/>
            <div data-reveal-target="pane" data-reveal-key="garage" @if ($way !== 'garage') hidden @endif>
                <form method="post" action="/offers/{{ $n }}/garage-holder" class="flex flex-col gap-4" data-controller="reveal">
                    @csrf
                    @error('holder')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
                    <div class="flex flex-col gap-1.5">
                        <span class="field-label">Кому</span>
                        <x-ui.segment name="whom" :options="$options" :value="$whom" pick/>
                    </div>
                    @if ($toBuyer)
                        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="buyer" @if ($whom !== 'buyer') hidden @endif>
                            <x-people.pick name="manager_id" label="Покупатель" kind="garage-buyer" :people="\App\Support\People::garageBuyers()" title="Покупатель" placeholder="Кому передаём"/>
                            <x-ui.field name="buyer_price" label="Цена для покупателя, ₽" :value="old('buyer_price', $car->buyer_price ?? $offer->asking_price)" :id="$id.'-price'" required/>
                        </div>
                    @endif
                    <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="manager" @if ($whom !== 'manager') hidden @endif>
                        <x-people.pick name="manager_id" label="Менеджер" kind="manager" :people="\App\Support\People::managers()" title="Менеджер" placeholder="Кому передаём"/>
                    </div>
                    <x-ui.sheet-foot type="submit" variant="primary">Передать</x-ui.sheet-foot>
                </form>
            </div>
            <div data-reveal-target="pane" data-reveal-key="deal" @if ($way !== 'deal') hidden @endif>
                @if ($dealBlocked)
                    <x-ui.flash tone="danger">{{ $dealBlocked }}</x-ui.flash>
                @else
                    <x-offer.give-deal :offer="$offer" :prefix="$id.'-deal'" :cost="$cost" :letter="false"
                        :note="'Из гаража'.($car->manager ? ' '.$car->manager->shortName() : '').($ours > 0 ? ', в закупочной наши расходы '.Money::rub($ours) : '')"/>
                @endif
            </div>
        </div>
    </x-ui.sheet>
</div>
