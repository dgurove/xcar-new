@php $qs = fn (array $extra = []) => http_build_query(array_filter($filters + $extra, fn ($v) => $v !== null && $v !== '')); @endphp
<x-ui.shell :title="$purchase->publicTitle()" back="/zakupki" :wide="true">
    <div class="mb-4 flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @if ($purchase->acceptsOffers() && $purchase->offers_close_at)
                <span class="chip"><x-ui.icon name="clock" class="size-4"/> <span class="tabular-nums" data-controller="timer" data-timer-until-value="{{ $purchase->offers_close_at->toIso8601String() }}"></span></span>
            @elseif (!$purchase->acceptsOffers())
                <span class="chip bg-closed-soft text-closed">Приём закрыт</span>
            @endif
            <span class="chip tabular-nums">пройдено {{ $done }} из {{ $total }}</span>
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3 min-w-24"><div class="h-full bg-accent" style="width:{{ $total ? round($done / $total * 100) : 0 }}%"></div></div>
        </div>
        <form method="get" class="flex gap-2" data-controller="autosubmit">
            @foreach (['preset', 'kind'] as $k)@if (!empty($filters[$k]))<input type="hidden" name="{{ $k }}" value="{{ $filters[$k] }}">@endif @endforeach
            <label class="relative flex-1">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-dim"/>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ДЛ, VIN, марка" class="field-input !bg-surface pl-11" enterkeyhint="search">
            </label>
            <select name="sort" class="field-input !w-auto !bg-surface" data-action="autosubmit#submit" aria-label="Сортировка">
                @foreach (\App\Http\Site\PurchaseController::SORTS as $k => $l)<option value="{{ $k }}" @selected(($filters['sort'] ?? 'dl') === $k)>{{ $l }}</option>@endforeach
            </select>
        </form>
        <x-ui.presets :items="\App\Http\Site\PurchaseController::PRESETS" :current="$filters['preset'] ?? 'all'"/>
        @if (count($kinds) > 1)
            <div class="presets">
                <a href="{{ request()->fullUrlWithQuery(['kind' => null, 'page' => null]) }}" class="preset" @if (empty($filters['kind'])) aria-current="true" @endif>Вся техника</a>
                @foreach ($kinds as $kind)<a href="{{ request()->fullUrlWithQuery(['kind' => $kind->value, 'page' => null]) }}" class="preset" @if (($filters['kind'] ?? null) === $kind->value) aria-current="true" @endif>{{ $kind->label() }}</a>@endforeach
            </div>
        @endif
    </div>

    @if ($cars->isEmpty())
        <div class="py-24 text-center text-ink-muted">Ничего не нашлось</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($cars as $car)
                @php $mine = $car->offerOf(auth()->user()); @endphp
                <a href="/zakupki/{{ $purchase->number }}/{{ $car->ref }}{{ $qs() ? '?'.$qs() : '' }}" class="row items-start">
                    @if ($car->mainPhoto())<div class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></div>@endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate font-medium">{{ $car->titleWithYear() }}</span>
                            <span class="shrink-0 text-sm text-ink-dim">{{ $car->dl }}</span>
                        </div>
                        <div class="truncate text-sm text-ink-muted">{{ implode(' · ', array_filter([...array_slice($car->facts(), 0, 3), $car->settlement?->name ?? $car->city])) }}</div>
                        <div class="mt-1 flex items-center gap-2 text-sm">
                            @if ($mine)<span class="font-semibold tabular-nums">{{ number_format($mine->amount, 0, '', ' ') }} ₽</span>@endif
                            @if ($car->fssp)<span class="chip bg-urgent-soft text-urgent">ФССП</span>@endif
                            @if ($car->photos_count)<span class="text-ink-dim"><x-ui.icon name="photo" class="inline size-4"/> {{ $car->photos_count }}</span>@endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $cars->links() }}</div>
    @endif
</x-ui.shell>
