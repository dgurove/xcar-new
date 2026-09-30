{{-- Машины группами по этапу: чинятся, проданы (идёт расчёт), рассчитались. Строка — кадр, название, одна
     фраза этапа и справа главное число этапа: вложено, осталось отдать или цена продажи. Сотрудник видит всех и чьи. --}}
@php
    use App\Garage\CarState;
    use App\Support\Money;
    use App\Support\Plural;
    $staff = auth()->user()->isStaff();
    $groups = ['Чинятся' => CarState::Repair, 'Проданы' => CarState::Sold, 'Рассчитались' => CarState::Settled];
@endphp
<x-ui.shell title="Гараж" :count="$cars->count() ?: null">
    @if ($cars->isEmpty())
        <x-ui.empty class="mt-2">В гараже пусто</x-ui.empty>
    @else
        <div class="flex max-w-[56rem] flex-col">
            @foreach ($groups as $head => $state)
                @php $rows = $cars->where('state', $state); @endphp
                @continue($rows->isEmpty())
                <h2 class="list-head">{{ $head }}<span class="nums text-base font-normal text-ink-muted">{{ $rows->count() }}</span></h2>
                <div class="list mb-4">
                    @foreach ($rows as $car)
                        @php
                            $offer = $car->offer;
                            $photo = $offer->mainPhoto();
                            $invoice = $car->invoice;
                            $days = $car->days();
                            $who = $staff ? ($car->manager?->shortName() ?? 'взяли под себя').', ' : '';
                            [$sub, $value, $caption] = match ($state) {
                                CarState::Repair => [$who.$days.' '.Plural::of($days, ['день', 'дня', 'дней']).($car->costs->isNotEmpty() ? ', '.$car->costs->count().' '.Plural::of($car->costs->count(), ['расход', 'расхода', 'расходов']) : ''), $car->invested(), 'вложено'],
                                CarState::Sold => [$who.'продана '.$car->sold_at->translatedFormat('j M'),
                                    $invoice ? $invoice->remaining() : $car->sold_price,
                                    $invoice ? ($invoice->isOwed() ? ($staff ? 'отдаём' : 'к выплате') : ($staff ? 'отдаёт нам' : 'отдать нам')) : ($staff ? 'счёта нет' : 'продана за')],
                                default => [$who.'рассчитались '.($car->settled_at ?? $car->sold_at)->translatedFormat('j M'), $car->sold_price, 'продана за'],
                            };
                        @endphp
                        <a href="/garage/cars/{{ $offer->number }}" class="row">
                            {{-- Кадр — как у строк сделок (.row-photo); нет фото — силуэт в той же клетке. --}}
                            <span class="row-photo flex items-center justify-center text-ink-dim">@if ($photo)<x-offer.photo :media="$photo" sizes="72px"/>@else<x-ui.icon name="cat-car" class="size-7"/>@endif</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $offer->titleWithYear() }}</span>
                                <span class="row-sub">{{ $sub }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="nums block">{{ Money::exact($value) }}</span>
                                <span class="block text-sm text-ink-muted">{{ $caption }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
