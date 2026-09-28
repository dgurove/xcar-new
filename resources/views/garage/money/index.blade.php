{{-- Расчёты: проданные машины и что по ним с деньгами. Менеджеру — свои. --}}
@php use App\Support\Money; $staff = auth()->user()->isStaff(); @endphp
<x-ui.shell title="Расчёты" :count="$cars->count() ?: null">
    @if ($cars->isEmpty())
        <x-ui.empty class="mt-6">Проданных машин пока нет</x-ui.empty>
    @else
        <div class="list mt-4">
            @foreach ($cars as $car)
                @php
                    $s = \App\Garage\Settlement::of($car);
                    $invoice = $car->invoice;
                    $facts = [$car->sold_at?->translatedFormat('j M Y')];
                    if ($staff) $facts[] = $car->manager?->shortName() ?? 'взяли под себя';
                    $facts[] = $invoice ? 'счёт '.mb_strtolower($invoice->state->label()) : 'счёта нет';
                @endphp
                <a href="/cars/{{ $car->offer->number }}" class="row">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $car->offer->titleWithYear() }}</span>
                        <span class="row-sub">{{ implode(', ', array_filter($facts)) }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="nums block">{{ Money::exact($invoice && $invoice->remaining() > 0 ? $invoice->remaining() : ($s['due'] ?? 0)) }}</span>
                        <span class="block text-sm text-ink-muted">{{ $invoice && $invoice->remaining() <= 0 ? 'рассчитались' : ($staff ? 'отдаёт нам' : 'отдать нам') }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
