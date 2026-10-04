{{-- Расходы машины строками, итог — в заголовке; свою строку человек правит в её шторке. --}}
@if ($car->costs->isNotEmpty())
    <section>
        <h2 class="list-head lg:pt-0">Расходы<span class="nums ml-auto text-base font-normal text-ink-muted">{{ \App\Support\Money::exact($car->spent()) }}</span></h2>
        <div class="list">
            @foreach ($car->costs as $cost)
                @php $mine = $cost->editableBy($user); @endphp
                {{-- Своя шторка у каждой строки: у контроллера шторки ровно один диалог. --}}
                <div @if ($mine) data-controller="sheet" @endif>
                    <{{ $mine ? 'button type=button data-action=sheet#open' : 'div' }} class="row w-full text-left">
                        <span class="min-w-0 flex-1">
                            <span class="block">{{ $cost->title }}</span>
                            <span class="row-sub">{{ $cost->spent_at->translatedFormat('j F') }}@if ($cost->payer === \App\Garage\Payer::Xcar), платили мы@endif</span>
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
    </section>
@endif
