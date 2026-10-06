{{-- Расходы машины строками, итог — в заголовке; строку правят в её шторке. Сотруднику список виден всегда, «+ Расход»
     — в заголовке (06.10.2026, владелец: «добавлять, убирать, корректировать»); менеджеру — когда есть что показать. --}}
@if ($car->costs->isNotEmpty() || ($staff && $canAdd))
    <section>
        <h2 class="list-head lg:pt-0">Расходы@if ($car->costs->isNotEmpty())<span class="nums text-base font-normal text-ink-muted">{{ \App\Support\Money::exact($car->spent()) }}</span>@endif
            @if ($staff && $canAdd)
                <button type="button" class="btn btn-s btn-quiet ml-auto self-center" data-controller="emit" data-action="emit#send" data-emit-event-param="cost-new:open"><x-ui.icon name="plus" class="size-4"/>Расход</button>
            @endif
        </h2>
        @if ($car->costs->isNotEmpty())
            <div class="list">
                @foreach ($car->costs as $cost)
                    @php $mine = $cost->editableBy($user); @endphp
                    {{-- Своя шторка у каждой строки: у контроллера шторки ровно один диалог. --}}
                    <div @if ($mine) data-controller="sheet" @endif>
                        <{{ $mine ? 'button type=button data-action=sheet#open' : 'div' }} class="row w-full text-left">
                            <span class="min-w-0 flex-1">
                                <span class="block">{{ $cost->title }}</span>
                                <span class="row-sub">{{ $cost->spent_at->translatedFormat('j F') }}@if ($cost->payer === \App\Garage\Payer::Xcar), платили мы@elseif ($staff), платил менеджер@endif</span>
                            </span>
                            <span class="nums shrink-0">{{ \App\Support\Money::exact($cost->amount) }}</span>
                            @if ($mine)<x-ui.chevron/>@endif
                        </{{ $mine ? 'button' : 'div' }}>
                        @if ($mine)
                            <x-ui.sheet id="cost-{{ $cost->id }}" title="Расход">
                                <form method="post" action="/garage/costs/{{ $cost->id }}" class="flex flex-col gap-4">
                                    @csrf @method('put')
                                    @include('garage.cars.cost-fields', ['cost' => $cost])
                                    <x-ui.button type="submit" variant="primary" block>Сохранить</x-ui.button>
                                </form>
                                <form method="post" action="/garage/costs/{{ $cost->id }}" class="mt-2" data-turbo-confirm="Убрать расход?">
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
@endif
