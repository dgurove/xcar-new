{{-- Машина в гараже. Телефон: кадр, деньги одной плашкой, расходы; внизу одна кнопка того, что сейчас
     надо сделать, остальное — в «⋯». ПК: слева расходы (работа), справа кадр и деньги.
     Шторки: расход (новый и каждый свой), «Продана», счёт с вознаграждением, «Поступило», «Сообщить об оплате». --}}
@php
    use App\Garage\CarState;
    use App\Garage\Payer;
    use App\Support\Money;
    use App\Support\Plural;
    $offer = $car->offer;
    $user = auth()->user();
    $staff = $user->isStaff();
    $n = $offer->number;
    $photo = $offer->mainPhoto();
    $s = \App\Garage\Settlement::of($car);
    $invoice = $car->invoice;
    $unpaid = $invoice && $invoice->remaining() > 0;
    $frozen = $car->isFrozen();
    $canAdd = ! $frozen && ($staff || ! $car->isSold());
    // Главная кнопка этапа и то, что уходит в «⋯».
    $primary = match (true) {
        ! $staff && ! $car->isSold() => ['cost-new', 'Записать расход'],
        ! $staff && $unpaid && ! $invoice->isOwed() && $invoice->remaining() - $invoice->claimed() > 0 => ['claim', 'Сообщить об оплате'],
        $staff && ! $car->isSold() => ['sold', 'Продана'],
        $staff && $car->state === CarState::Sold && ! $invoice => ['settle', $car->manager ? 'Выставить счёт' : 'Закрыть расчёт'],
        $staff && $unpaid => ['paid', $invoice->isOwed() ? 'Выплатили' : 'Поступило'],
        default => null,
    };
    $more = array_filter([
        $staff && $canAdd ? ['emit', 'cost-new', 'Записать расход'] : null,
        $staff && $car->state === CarState::Sold && ! $invoice ? ['form', 'DELETE', "/cars/{$n}/sold", 'Не продана', 'Снять итог продажи?'] : null,
        $staff && $unpaid ? ['form', 'DELETE', "/cars/{$n}/invoice", 'Аннулировать', 'Аннулировать документ? Расходы и итог снова можно будет поправить'] : null,
        $staff && ! $car->isSold() && $car->costs->isEmpty() ? ['form', 'DELETE', "/cars/{$n}", 'Отдали по ошибке', 'Вернуть машину в черновики?'] : null,
    ]);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Машины', '/']">
    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-1.5">
        <x-ui.pill :tone="$car->state->tone()">{{ $car->state->label() }}</x-ui.pill>
        <span class="chip nums">{{ $car->days() }} {{ Plural::of($car->days(), ['день', 'дня', 'дней']) }} в гараже</span>
        @if ($staff)<span class="chip">{{ $car->manager?->shortName() ?? 'Взяли под себя' }}</span>@endif
        @if ($offer->vin)<span class="chip nums">{{ $offer->vin }}</span>@endif
    </div>
    @error('car')<x-ui.flash tone="danger" class="mb-4">{{ $message }}</x-ui.flash>@enderror

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <aside class="flex flex-col gap-4 lg:sticky lg:top-24 lg:order-last">
            @if ($photo)
                <img src="{{ \App\Media\MediaUrl::for($photo, 'w640') }}" alt="" width="640" height="480" class="aspect-video w-full rounded-(--radius-l) object-cover lg:aspect-[4/3]">
            @endif
            @include('garage.cars.money')
        </aside>

        <section>
            @if ($car->costs->isNotEmpty())
                <h2 class="list-head lg:pt-0">Расходы</h2>
                <div class="list">
                    @foreach ($car->costs as $cost)
                        @php $mine = $cost->editableBy($user); @endphp
                        {{-- Своя шторка у каждой строки: у контроллера шторки ровно один диалог. --}}
                        <div @if ($mine) data-controller="sheet" @endif>
                            <{{ $mine ? 'button type=button data-action=sheet#open' : 'div' }} class="row w-full text-left">
                                <span class="min-w-0 flex-1">
                                    <span class="block">{{ $cost->title }}</span>
                                    <span class="row-sub">{{ $cost->spent_at->translatedFormat('j F') }}@if ($cost->payer === Payer::Xcar), платили мы@endif</span>
                                </span>
                                <span class="nums shrink-0">{{ Money::exact($cost->amount) }}</span>
                            </{{ $mine ? 'button' : 'div' }}>
                            @if ($mine)
                                <x-ui.sheet id="cost-{{ $cost->id }}" title="Расход">
                                    <form method="post" action="/costs/{{ $cost->id }}" class="flex flex-col gap-4">
                                        @csrf @method('put')
                                        @include('garage.cars.cost-fields', ['cost' => $cost])
                                        <x-ui.button type="submit" variant="primary" block>Сохранить</x-ui.button>
                                    </form>
                                    <form method="post" action="/costs/{{ $cost->id }}" class="mt-2" data-turbo-confirm="Убрать расход?">
                                        @csrf @method('delete')
                                        <x-ui.button type="submit" variant="ghost" block>Убрать</x-ui.button>
                                    </form>
                                </x-ui.sheet>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    @if ($primary || $more)
        <x-ui.action-bar>
            @if ($primary)
                <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $primary[0] }}:open">
                    @if ($primary[0] === 'cost-new')<x-ui.icon name="plus" class="size-5"/>@endif{{ $primary[1] }}
                </button>
            @endif
            @if ($more)
                <span data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-quiet btn-round btn-lg {{ $primary ? '' : 'ml-auto' }}" data-action="sheet#open" aria-label="Ещё"><x-ui.icon name="more" class="size-6"/></button>
                    <x-ui.sheet id="car-more" title="{{ $offer->titleWithYear() }}">
                        <div class="flex flex-col gap-2">
                            @foreach ($more as $item)
                                @if ($item[0] === 'emit')
                                    <x-ui.button type="button" variant="secondary" block data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="{{ $item[1] }}:open">{{ $item[2] }}</x-ui.button>
                                @else
                                    <form method="post" action="{{ $item[2] }}" data-turbo-confirm="{{ $item[4] }}">@csrf @method($item[1])<x-ui.button type="submit" variant="danger" block>{{ $item[3] }}</x-ui.button></form>
                                @endif
                            @endforeach
                        </div>
                    </x-ui.sheet>
                </span>
            @endif
        </x-ui.action-bar>
    @endif

    @include('garage.cars.sheets')
</x-ui.shell>
