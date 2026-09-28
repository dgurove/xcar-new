{{-- Машина в гараже: сколько в неё вложено, из чего это сложилось и одна кнопка «Записать расход».
     Строка расхода нажимается — та же шторка с «Убрать». --}}
@php
    use App\Support\Money;
    use App\Support\Plural;
    $offer = $car->offer;
    $user = auth()->user();
    $staff = $user->isStaff();
    $photo = $offer->mainPhoto();
    $mine = $car->spent(\App\Garage\Payer::Manager);
    $ours = $car->spent(\App\Garage\Payer::Xcar);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Машины', '/']">
    <div class="mt-4 flex max-w-[34rem] flex-col gap-4">
        <div class="box">
            <div class="flex items-start gap-4">
                @if ($photo)
                    <img src="{{ \App\Media\MediaUrl::for($photo, 'thumb') }}" alt="" width="128" height="96" class="size-20 shrink-0 rounded-(--radius-m) object-cover">
                @endif
                <div class="min-w-0">
                    <span class="nums block text-[32px] leading-none font-bold">{{ Money::exact($car->invested()) }}</span>
                    <span class="mt-1 block text-sm text-ink-muted">вложено в машину</span>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <x-ui.pill :tone="$car->state->tone()" class="!min-h-0 !py-1 text-xs">{{ $car->state->label() }}</x-ui.pill>
                        <span class="chip nums">{{ $car->days() }}&nbsp;{{ Plural::of($car->days(), ['день', 'дня', 'дней']) }}</span>
                        @if ($staff && $car->manager)<span class="chip">{{ $car->manager->shortName() }}</span>@endif
                    </div>
                </div>
            </div>
            <div class="list mt-5">
                @if ($car->cost)
                    <div class="row"><span class="flex-1">Отдали за</span><span class="nums shrink-0">{{ Money::rub($car->cost) }}</span></div>
                @endif
                @if ($mine > 0)
                    <div class="row"><span class="flex-1">Расходы{{ $staff && $ours > 0 ? ' менеджера' : '' }}</span><span class="nums shrink-0">{{ Money::exact($mine) }}</span></div>
                @endif
                @if ($staff && $ours > 0)
                    <div class="row"><span class="flex-1">Расходы XCar</span><span class="nums shrink-0">{{ Money::exact($ours) }}</span></div>
                @endif
            </div>
        </div>

        @if ($car->isSold())
            @include('garage.cars.settlement')
        @endif

        <div>
            <h2 class="mb-2 text-lg">Расходы</h2>
            @if ($car->costs->isEmpty())
                <x-ui.empty>Расходов пока нет</x-ui.empty>
            @else
                <div class="list">
                    @foreach ($car->costs as $cost)
                        {{-- Каждая строка со своей шторкой: у контроллера шторки ровно один диалог. --}}
                        <div data-controller="sheet">
                            <button type="button" class="row w-full text-left" data-action="sheet#open">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium">{{ $cost->title }}</span>
                                    <span class="row-sub">{{ $cost->spent_at->translatedFormat('j F Y') }}@if ($staff && $cost->payer === \App\Garage\Payer::Xcar), заплатили мы@endif</span>
                                </span>
                                <span class="nums shrink-0">{{ Money::exact($cost->amount) }}</span>
                            </button>
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
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @unless ($car->isSold() && ! $staff)
            <div data-controller="sheet">
                <x-ui.action-bar>
                    <button type="button" class="btn {{ $staff && ! $car->isSold() ? 'btn-quiet' : 'btn-accent' }} min-w-0 flex-1" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Записать расход</button>
                    @if ($staff && ! $car->isSold())
                        <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">Продана</button>
                    @endif
                </x-ui.action-bar>
                <x-ui.sheet id="cost-new" title="Записать расход" :open="$errors->any()">
                    <form method="post" action="/cars/{{ $offer->number }}/costs" class="flex flex-col gap-4">
                        @csrf
                        @include('garage.cars.cost-fields', ['cost' => null])
                        <x-ui.button type="submit" variant="primary" block>Готово</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endunless

        @if ($staff && ! $car->isSold())
            <div data-controller="sheet" data-action="sold:open@window->sheet#open" class="contents">
                <x-ui.sheet id="sold" title="Продана" :open="$errors->has('sold_price')">
                    <form method="post" action="/cars/{{ $offer->number }}/sold" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="sold_price" label="За сколько, ₽" :value="old('sold_price')"/>
                        <x-ui.field name="sold_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                        <x-ui.field name="buyer_name" label="Покупатель" :value="old('buyer_name')" placeholder="Кому продал"/>
                        <x-ui.field name="buyer_phone" label="Телефон" :value="old('buyer_phone')"/>
                        <x-ui.button type="submit" variant="primary" block>Продана</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endif

        @if ($staff && ! $car->isSold() && $car->costs->isEmpty())
            <form method="post" action="/cars/{{ $offer->number }}" data-turbo-confirm="Вернуть машину в черновики?">
                @csrf @method('delete')
                <x-ui.button type="submit" variant="ghost" block>Отдали по ошибке</x-ui.button>
            </form>
        @endif
    </div>
</x-ui.shell>
