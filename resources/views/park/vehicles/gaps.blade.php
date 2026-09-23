{{-- Дозаполнить то, из-за чего ТС не считается: тип и заявленную стоимость. Тип предзаполнен догадкой по
     марке и модели, стоимость берут из письма страховой. Одна кнопка на весь список. --}}
@php use App\Cars\Category; @endphp
<x-ui.shell title="Дозаполнить" :count="$vehicles->count()" :back="['Наличие', '/cars?gap=rate']">
    @if ($vehicles->isEmpty())
        <x-ui.empty class="mt-6">Считается всё</x-ui.empty>
    @else
        <form method="post" action="/cars/gaps" class="mt-4 flex flex-col gap-2">
            @csrf
            @foreach ($vehicles as $vehicle)
                @php $guess = $vehicle->category?->value ?? Category::guess(trim($vehicle->brand?->name.' '.$vehicle->model?->name))?->value ?? Category::Passenger->value; @endphp
                <div class="row flex-wrap items-end gap-3 sm:flex-nowrap">
                    <span class="min-w-0 flex-1">
                        <a href="/cars/{{ $vehicle->id }}" class="block font-medium hover:text-accent-text">{{ $vehicle->titleWithYear() }}</a>
                        <span class="row-sub mt-1 flex flex-wrap items-center gap-1.5">
                            @if ($vehicle->ref)<x-ui.copy-code class="tag" :value="$vehicle->ref"/>@endif
                            @if ($vehicle->vendor)<span class="tag">{{ $vehicle->vendor->name }}</span>@endif
                            @if ($vehicle->yard)<x-ui.place class="tag">{{ $vehicle->yard->name }}</x-ui.place>@endif
                            @if ($vehicle->daysStored() !== null)<span class="tag nums">{{ $vehicle->daysStored() }} дн</span>@endif
                        </span>
                    </span>
                    <x-ui.field :name="'cars['.$vehicle->id.'][category]'" label="Тип" :options="$categories" :value="$guess" class="w-40"/>
                    <x-ui.field :name="'cars['.$vehicle->id.'][value]'" label="Стоимость, ₽" :value="$vehicle->value" inputmode="numeric" class="w-36"/>
                </div>
            @endforeach
            <x-ui.action-bar><x-ui.button class="min-w-0 flex-1">Сохранить</x-ui.button></x-ui.action-bar>
        </form>
    @endif
</x-ui.shell>
