{{-- Оценки машины в закупке — ориентир для цены продажи: последняя цена каждого менеджера по сумме вниз
     (`Purchases\Car::latestOfferList`), под ними админская. Окошко предложения: под «Оценить» и плашкой у оценённых. --}}
@php $named = $car->latestOfferList(); @endphp
@if ($car->price_final || $named->isNotEmpty())
    <dl class="mt-3 flex flex-col gap-2 text-sm">
        @foreach ($named as $one)
            <div class="flex items-center justify-between gap-3">
                <dt class="flex min-w-0 items-center gap-2"><x-ui.avatar :user="$one->user" :size="20"/><span class="truncate">{{ $one->user->shortName() }}</span></dt>
                <dd class="nums whitespace-nowrap">{{ \App\Support\Money::rub($one->amount) }}</dd>
            </div>
        @endforeach
        @if ($car->price_final)
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-dim">Админская цена</dt><dd class="nums font-medium">{{ \App\Support\Money::rub($car->price_final) }}</dd></div>
        @endif
    </dl>
@endif
