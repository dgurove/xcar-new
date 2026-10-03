{{-- Машины одним списком, как в приложении: сначала то, где ход менеджера, дальше по пути (ждёт страховую → … →
     продана), внутри — дольше стоящие первыми; рассчитанные — своей группой ниже. Строка — кадр, название, этап цветным
     словом с днями на нём (ждут ответа — «ваш ход» и что сделать) и справа главное число: расходы, отдать нам, к выплате.
     Менеджер закупочную не видит: у него расходы, а не «вложено». Сотрудник видит всех и чьи. --}}
@php
    use App\Garage\CarState;
    use App\Support\Money;
    use App\Support\Plural;
    $staff = auth()->user()->isStaff();
    $days = fn (int $d) => $d.' '.Plural::of($d, ['день', 'дня', 'дней']);
    [$closed, $open] = $cars->partition(fn ($c) => $c->state === CarState::Settled);
    $open = $open->sortBy(fn ($c) => [$c->deal?->openRequirement ? 0 : 1, $c->state->order(), $c->stage_at?->timestamp ?? 0])->values();
    $groups = array_filter(['' => $open, 'Рассчитались' => $closed], fn ($g) => $g->isNotEmpty());
@endphp
<x-ui.shell title="Гараж" :count="$open->count() ?: null">
    @if ($cars->isEmpty())
        <x-ui.empty class="mt-2">В гараже пусто</x-ui.empty>
    @else
        <div class="flex max-w-[56rem] flex-col">
            @foreach ($groups as $head => $rows)
                @if ($head)<h2 class="list-head">{{ $head }}<span class="nums text-base font-normal text-ink-muted">{{ $rows->count() }}</span></h2>@endif
                <div class="list mb-4">
                    @foreach ($rows as $car)
                        @php
                            $offer = $car->offer;
                            $photo = $offer->mainPhoto();
                            $current = $car->payoutInvoice ?? $car->invoice;
                            $asks = $car->manager_id === auth()->id() ? $car->deal?->openRequirement : null;
                            $tone = $asks ? 'text-urgent' : match ($car->state->tone()) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted' };
                            $word = $asks ? 'ваш ход: '.mb_strtolower($asks->title) : mb_strtolower($car->state->label());
                            $when = match ($car->state) {
                                CarState::Settled => ($car->settled_at ?? $car->sold_at)?->translatedFormat('j M'),
                                CarState::Sold => 'с '.$car->sold_at?->translatedFormat('j M'),
                                default => $days($car->stageDays()),
                            };
                            [$value, $caption] = match ($car->state) {
                                CarState::Waiting => [null, null],
                                CarState::Delivery, CarState::Repair, CarState::Selling => [$staff ? $car->invested() : $car->spent(), $staff ? 'вложено' : 'расходы'],
                                CarState::Sold => $current && $current->remaining() > 0
                                    ? [$current->remaining(), $current->isOwed() ? ($staff ? 'отдаём' : 'к выплате') : ($car->invoice_to === 'buyer' ? 'платит покупатель' : ($staff ? 'отдаёт нам' : 'отдать нам'))]
                                    : [$car->sold_price, $staff ? 'счёта нет' : 'продана за'],
                                default => [$car->sold_price, 'продана за'],
                            };
                            $sub = array_filter([$when, $staff ? ($car->manager?->shortName() ?? 'взяли под себя') : null]);
                        @endphp
                        <a href="/garage/cars/{{ $offer->number }}" class="row">
                            {{-- Кадр — как у строк сделок (.row-photo); нет фото — силуэт в той же клетке. --}}
                            <span class="row-photo flex items-center justify-center text-ink-dim">@if ($photo)<x-offer.photo :media="$photo" sizes="72px"/>@else<x-ui.icon name="cat-car" class="size-7"/>@endif</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $offer->titleWithYear() }}</span>
                                <span class="row-sub block truncate"><span class="{{ $tone }}">{{ $word }}</span>@if ($sub), {{ implode(', ', $sub) }}@endif</span>
                            </span>
                            @if ($caption && (float) $value > 0)
                                <span class="shrink-0 text-right">
                                    <span class="nums block">{{ Money::exact($value) }}</span>
                                    <span class="block text-sm text-ink-muted">{{ $caption }}</span>
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
