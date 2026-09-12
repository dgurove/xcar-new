@php
    $qs = http_build_query(array_filter($filters, fn ($v) => $v !== null && $v !== ''));
    $suffix = $qs ? '?'.$qs : '';
    $back = '/zakupki/'.$purchase->number.$suffix;
    $asSheet = $purchase->acceptsOffers() && $mine?->state !== \App\Purchases\OfferState::Chosen;
    $facts = array_filter([
        'Тип' => $car->kind->label(), 'Год' => $car->year, 'Пробег' => $car->mileage !== null ? number_format($car->mileage, 0, '', ' ').' км' : null,
        'Коробка' => $car->transmission?->label(), 'Топливо' => $car->fuel?->label(), 'Объём' => $car->engine_volume ? number_format($car->engine_volume / 1000, 1, ',', '').' л' : null,
        'Мощность' => $car->engine_power ? $car->engine_power.' л. с.' : null, 'Цвет' => $car->color, 'Руль' => $car->steering, 'Ключи' => $car->keys,
        'Состояние' => $car->condition, 'VIN' => $car->vin, 'Номер' => $car->dl, 'Где' => $car->settlement?->name ?? $car->city ?? $car->address, 'Обременения' => $car->encumbrance,
    ], fn ($v) => $v !== null && $v !== '');
@endphp
<x-ui.shell :title="$car->titleWithYear()" :trail="[['Главная', '/'], ['Закупки', '/zakupki'], [$purchase->publicTitle(), $back], [$car->dl]]">
    <x-slot:actions>
        <x-ui.nav-arrows class="ml-auto sm:ml-0"
            :prev="$prev ? '/zakupki/'.$purchase->number.'/'.$prev->ref.$suffix : null"
            :next="$next ? '/zakupki/'.$purchase->number.'/'.$next->ref.$suffix : null"
            :back="$back"/>
    </x-slot:actions>

    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5">
        <x-purchase.deadline :purchase="$purchase"/>
        @if ($mine)<x-ui.pill tone="soft">Ваша цена {{ number_format($mine->amount, 0, '', ' ') }} ₽</x-ui.pill>@endif
        @if ($car->fssp)<x-ui.pill tone="urgent">Ограничения ФССП</x-ui.pill>@endif
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
        @if ($photos->isNotEmpty())
            <div class="min-w-0 lg:col-start-1 lg:row-start-1"><x-offer.gallery :photos="$photos" :alt="$car->titleWithYear()"/></div>
        @endif

        <aside class="lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:sticky lg:top-32 lg:self-start">
            <div @if ($asSheet) data-controller="sheet" data-sheet-inflow-value="(min-width: 1024px)" data-action="deal:open@window->sheet#open" @endif class="contents">
                <{{ $asSheet ? 'dialog' : 'div' }} id="deal" class="{{ $asSheet ? 'sheet sheet--inflow' : 'box' }}" @if ($asSheet) data-sheet-target="dialog" data-action="click->sheet#backdrop" aria-label="Цена" @endif @if ($asSheet && $errors->any()) data-sheet-open-value="true" @endif>
                    @if ($asSheet)<div class="mb-2 flex justify-end lg:hidden"><button type="button" class="sheet-close" data-action="sheet#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button></div>@endif
                    <h2 class="text-xl">Предложение</h2>
                    @if ($mine?->state === \App\Purchases\OfferState::Chosen)
                        <p class="flash flash-accent mt-4">Ваша цена {{ number_format($mine->amount, 0, '', ' ') }} ₽ выбрана — с Вами свяжутся</p>
                    @elseif ($purchase->acceptsOffers())
                        <form method="post" action="/zakupki/{{ $purchase->number }}/{{ $car->ref }}/cena{{ $suffix }}" class="mt-4 flex flex-col gap-3" data-controller="bid" data-bid-asking-value="0" data-bid-min-value="0">
                            @csrf
                            <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $mine?->amount) }}">
                            <input type="text" inputmode="numeric" required class="field-input nums text-lg" placeholder="Предложение, ₽" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $mine?->amount ? number_format($mine->amount, 0, '', ' ') : '') }}" autocomplete="off">
                            @error('amount')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                            <textarea name="comment" rows="3" class="field-input !min-h-0 text-sm" placeholder="Комментарий">{{ old('comment', $mine?->comment) }}</textarea>
                            <button type="submit" class="btn btn-accent w-full" data-bid-target="submit">{{ $mine ? 'Изменить' : 'Предложить' }}</button>
                        </form>
                        @if ($mine)
                            <form method="post" action="/zakupki/ceny/{{ $mine->id }}/otozvat" class="mt-2">@csrf<button type="submit" class="w-full py-2 text-sm text-ink-dim hover:text-danger">Отозвать цену</button></form>
                        @endif
                    @else
                        <p class="mt-4 text-ink-muted">Приём цен закрыт</p>
                    @endif
                </{{ $asSheet ? 'dialog' : 'div' }}>
            </div>
        </aside>

        <div class="min-w-0 lg:col-start-1 {{ $photos->isNotEmpty() ? 'lg:row-start-2' : 'lg:row-start-1' }}">
            @if ($car->description)
                <section class="mb-8">
                    <h2 class="text-xl">Описание</h2>
                    <p class="mt-4 whitespace-pre-line text-ink-muted">{{ $car->description }}</p>
                </section>
            @endif
            <section>
                <h2 class="text-xl">Характеристики</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3">
                    @foreach ($facts as $label => $value)
                        <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-medium">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>
        </div>
    </div>

    @if ($asSheet)
        <x-ui.action-bar class="lg:hidden">
            <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="deal:open">{{ $mine ? 'Изменить цену' : 'Предложить цену' }}</button>
        </x-ui.action-bar>
    @endif
</x-ui.shell>
