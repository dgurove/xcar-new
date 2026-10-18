{{-- «Оплаты» логиста (18.10.2026, владелец: «цена и кто должен»): пилюли «Заказчики» — кто не заплатил за привезённое,
     «Исполнители» — кому из своих не заплачено, «XCar» — что должен XCar. Строка — заказчик или исполнитель: сколько заявок
     и сумма; нажатие — шторка с заявками, у каждой «Деньги пришли» / «Выплачено», внизу — все разом. Без счетов. --}}
@php
    use App\Http\Logistics\MoneyController;
    use App\Support\Money;
    use App\Support\Plural;
    $groups = $pill === 'clients' ? $clients : $executors;
    $side = $pill === 'clients' ? 'client' : 'executor';
    $field = $pill === 'clients' ? 'client_price' : 'executor_cost';
@endphp
<x-ui.shell title="Оплаты" :phone-heading="false">
    <x-ui.pills class="mb-4">
        @foreach (MoneyController::PILLS as $key => $label)
            <x-ui.pill :href="'/money?preset='.$key" :current="$pill === $key" data-turbo-action="replace">{{ $label }}@if ($counts[$key]) <span class="nums">{{ $counts[$key] }}</span>@endif</x-ui.pill>
        @endforeach
    </x-ui.pills>

    @if ($pill === 'xcar')
        @include('logistics.money.xcar')
    @elseif ($groups->isEmpty())
        <x-ui.empty class="mt-6">{{ $pill === 'clients' ? 'Заказчики всё оплатили' : 'Исполнителям всё выплачено' }}</x-ui.empty>
    @else
        <div class="list">
            @foreach ($groups as $key => $tasks)
                @php
                    $first = $tasks->first();
                    $name = $pill === 'clients' ? $first->client?->name : $first->executor?->name;
                    $sum = (int) $tasks->sum($field);
                @endphp
                <div class="contents" data-controller="sheet">
                    <x-ui.sheet-row>
                        <x-ui.avatar :name="$name" :user="$pill === 'executors' ? $first->executor : null" :size="44"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $name }}</span>
                            <span class="row-sub">{{ $tasks->count() }} {{ Plural::of($tasks->count(), ['заявка', 'заявки', 'заявок']) }}</span>
                        </span>
                        <span class="shrink-0 text-right"><span class="block nums {{ $pill === 'clients' ? 'text-danger' : '' }}">{{ Money::rub($sum) }}</span><span class="block text-sm text-ink-muted">{{ $pill === 'clients' ? 'должен' : 'к выплате' }}</span></span>
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="money-{{ $pill }}-{{ $key }}" :title="$name">
                        <div class="list">
                            @foreach ($tasks as $t)
                                <form method="post" action="/transports/{{ $t->id }}/paid" class="row items-center justify-between gap-3">
                                    @csrf
                                    <input type="hidden" name="side" value="{{ $side }}"><input type="hidden" name="paid" value="1">
                                    <a href="/transports?preset=all&peek={{ $t->id }}" class="min-w-0 flex-1">
                                        <span class="block truncate">{{ $t->title() }}</span>
                                        <span class="row-sub">{{ implode(', ', array_filter([$t->car?->plate, $t->delivered_at ? 'привёз '.\App\Support\Ago::day($t->delivered_at) : null])) }}</span>
                                    </a>
                                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                                        <span class="nums">{{ Money::rub((int) $t->{$field}) }}</span>
                                        <button class="btn btn-s btn-quiet">{{ $pill === 'clients' ? 'Деньги пришли' : 'Выплачено' }}</button>
                                    </span>
                                </form>
                            @endforeach
                        </div>
                        @if ($tasks->count() > 1)
                            <form method="post" action="/money/paid" class="mt-4" data-turbo-confirm="{{ $pill === 'clients' ? 'Отметить все '.$tasks->count().' оплаченными?' : 'Отметить все '.$tasks->count().' выплаченными?' }}">
                                @csrf
                                <input type="hidden" name="side" value="{{ $side }}">
                                @foreach ($tasks as $t)<input type="hidden" name="transports[]" value="{{ $t->id }}">@endforeach
                                <x-ui.sheet-foot>{{ $pill === 'clients' ? 'Пришли все '.Money::rub($sum) : 'Выплачено всё, '.Money::rub($sum) }}</x-ui.sheet-foot>
                            </form>
                        @endif
                    </x-ui.sheet>
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
