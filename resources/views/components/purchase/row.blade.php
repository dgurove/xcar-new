{{-- Закупка в списке: название, сколько машин оценено, шкала, срок. --}}
@props(['purchase', 'rated' => 0])
@php $cars = $purchase->cars_count ?? 0; @endphp
<a href="/zakupki/{{ $purchase->number }}" class="box rise flex flex-col gap-2 sm:grid sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-x-4">
    <span class="block min-w-0">
        <h3 class="text-[19px] leading-snug sm:text-[22px]">{{ $purchase->publicTitle() }}</h3>
        @if ($cars > 0)
            <span class="mt-2 block max-w-sm">
                @if ($rated >= $cars)
                    <span class="block text-sm text-ink-dim">Названы все цены</span>
                @else
                    <span class="nums block text-sm font-normal text-ink-dim">Названо {{ $rated }} из {{ $cars }} {{ \App\Support\Plural::of($cars, ['цены', 'цен', 'цен']) }}</span>
                    <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-surface-3"><span class="block h-full rounded-full bg-accent" style="width: {{ (int) round($rated / $cars * 100) }}%"></span></span>
                @endif
            </span>
        @endif
    </span>
    <x-purchase.deadline :purchase="$purchase" class="order-first self-start sm:order-none sm:self-auto"/>
</a>
