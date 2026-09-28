{{-- Машины в гараже строками: кадр, название, состояние и дни, справа — вложено.
     Сотрудник видит все машины и чьи они. --}}
@php use App\Support\Money; use App\Support\Plural; $staff = auth()->user()->isStaff(); @endphp
<x-ui.shell title="Машины" :count="$cars->count() ?: null">
    @if ($cars->isEmpty())
        <x-ui.empty class="mt-6">Машин в гараже нет</x-ui.empty>
    @else
        <div class="list mt-4">
            @foreach ($cars as $car)
                @php
                    $offer = $car->offer;
                    $photo = $offer->mainPhoto();
                    $facts = [$car->state->label(), $car->days().' '.Plural::of($car->days(), ['день', 'дня', 'дней']).' в гараже'];
                    if ($staff) $facts[] = $car->manager?->shortName() ?? 'взяли под себя';
                @endphp
                <a href="/cars/{{ $offer->number }}" class="row">
                    @if ($photo)
                        <img src="{{ \App\Media\MediaUrl::for($photo, 'thumb') }}" alt="" width="64" height="48" loading="lazy" class="size-12 shrink-0 rounded-lg object-cover">
                    @else
                        <span class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-surface-3 text-ink-dim"><x-ui.icon name="car" class="size-6"/></span>
                    @endif
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $offer->titleWithYear() }}</span>
                        <span class="row-sub">{{ implode(', ', $facts) }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="nums block">{{ Money::exact($car->invested()) }}</span>
                        <span class="block text-sm text-ink-muted">вложено</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
