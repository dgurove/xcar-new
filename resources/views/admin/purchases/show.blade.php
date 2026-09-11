@php use App\Purchases\{PurchaseState, ImportState}; $n = $purchase->number; @endphp
<x-ui.shell :title="'№ '.$n.' · '.($purchase->title ?: $purchase->publicTitle())" back="/admin/zakupki" :wide="true">
    <div class="mb-4 flex flex-wrap items-center gap-2" data-controller="sheet">
        <span class="chip {{ match($purchase->state->tone()) { 'open' => 'bg-open-soft text-open', 'plain' => '', default => 'bg-closed-soft text-closed' } }}">{{ $purchase->state->label() }}</span>
        @if ($purchase->offers_close_at)<span class="chip tabular-nums">до {{ $purchase->offers_close_at->translatedFormat('j M, H:i') }}</span>@endif
        @if ($purchase->state->isPublic())<a href="/zakupki/{{ $n }}" class="chip">На сайте →</a>@endif
        @if ($stats['pending'])<span class="chip bg-urgent-soft text-urgent">выкачка: {{ $stats['pending'] }}</span>@endif
        @if ($errors->any())<span class="field-error w-full">{{ $errors->first() }}</span>@endif
        <x-ui.button type="button" variant="secondary" size="sm" class="ml-auto" data-action="sheet#open"><x-ui.icon name="more" class="size-5"/></x-ui.button>
        <x-ui.sheet id="purchase-actions" title="Закупка № {{ $n }}">
            <form method="post" action="/admin/zakupki/{{ $n }}" class="flex flex-col gap-3">
                @csrf @method('put')
                <x-ui.field name="title" label="Название для нас" :value="$purchase->title"/>
                <x-ui.field name="supplier" label="Поставщик" :value="$purchase->supplier"/>
                <x-ui.field name="offers_close_at" label="Цены до" type="datetime-local" :value="$purchase->offers_close_at?->format('Y-m-d\TH:i')"/>
                <x-ui.button block variant="secondary">Сохранить</x-ui.button>
            </form>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($transitions as $next)
                    @if (in_array($next, match($purchase->state) { PurchaseState::Draft => [PurchaseState::Open, PurchaseState::Archived], PurchaseState::Open => [PurchaseState::Closed, PurchaseState::Draft], PurchaseState::Closed => [PurchaseState::Open, PurchaseState::Archived], PurchaseState::Archived => [PurchaseState::Draft] }, true))
                        <form method="post" action="/admin/zakupki/{{ $n }}/sostoyanie">@csrf<input type="hidden" name="state" value="{{ $next->value }}"><x-ui.button block :variant="$next === PurchaseState::Open ? 'primary' : 'secondary'">{{ match($next) { PurchaseState::Open => 'Открыть приём цен', PurchaseState::Closed => 'Закрыть приём', PurchaseState::Draft => 'В черновик', PurchaseState::Archived => 'В архив' } }}</x-ui.button></form>
                    @endif
                @endforeach
                <form method="post" action="/admin/zakupki/{{ $n }}/zanovo">@csrf<input type="hidden" name="specs" value="1"><x-ui.button block variant="ghost">Перечитать характеристики</x-ui.button></form>
                <form method="post" action="/admin/zakupki/{{ $n }}/zanovo">@csrf<input type="hidden" name="photos" value="1"><x-ui.button block variant="ghost">Дозабрать фотографии</x-ui.button></form>
            </div>
        </x-ui.sheet>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([['Машин', $stats['cars']], ['С фото', $stats['photos']], ['С ценами', $stats['priced']], ['Сумма лучших', $stats['sum'] ? number_format($stats['sum'], 0, '', ' ').' ₽'.($stats['ours'] ? ' · '.($stats['sum'] >= $stats['ours'] ? '+' : '−').number_format(abs($stats['sum'] - $stats['ours']), 0, '', ' ') : '') : '—']] as [$label, $value])
            <div class="box !p-4"><div class="text-sm text-ink-muted">{{ $label }}</div><div class="text-xl font-semibold tabular-nums">{{ $value }}</div></div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col gap-3">
        <div class="flex gap-2">
            <form method="post" action="/admin/zakupki/{{ $n }}/fayl" enctype="multipart/form-data" class="flex-1" data-controller="autosubmit">
                @csrf
                <label class="btn btn-secondary w-full cursor-pointer"><x-ui.icon name="plus" class="size-5"/> Загрузить xlsx<input type="file" name="file" accept=".xlsx" hidden data-action="change->autosubmit#submit"></label>
            </form>
            @if ($stats['cars'])<a href="/admin/zakupki/{{ $n }}/xlsx" class="btn btn-secondary flex-1" data-turbo="false"><x-ui.icon name="file" class="size-5"/> Выгрузить xlsx</a>@endif
        </div>
        @if ($stats['cars'])
        <form method="get" data-controller="autosubmit">
            @if ($preset !== 'all')<input type="hidden" name="preset" value="{{ $preset }}">@endif
            <label class="relative block"><x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-dim"/><input type="search" name="q" value="{{ $q }}" placeholder="ДЛ, VIN, марка" class="field-input !bg-surface pl-11" enterkeyhint="search"></label>
        </form>
        <x-ui.presets :items="\App\Http\Admin\PurchaseController::PRESETS" :current="$preset"/>
        @endif
    </div>

    <div class="flex flex-col gap-2">
        @foreach ($cars as $car)
            @php $best = $car->bestOffer(); $bad = $car->specs_state->needsAttention() || $car->photos_state->needsAttention(); @endphp
            <a href="/admin/zakupki/{{ $n }}/{{ $car->ref }}" class="row items-start">
                <div class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $car->titleWithYear() }}</span><span class="shrink-0 text-sm text-ink-dim">{{ $car->dl }}</span></div>
                    <div class="text-sm text-ink-muted">{{ implode(' · ', array_filter([$car->price_listing ? number_format($car->price_listing, 0, '', ' ').' ₽' : null, $car->kind->label(), $car->settlement?->name ?? $car->city])) }}</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-sm">
                        @if ($best)<span class="font-semibold tabular-nums {{ $best->state === \App\Purchases\OfferState::Chosen ? 'text-accent-text' : '' }}">{{ number_format($best->amount, 0, '', ' ') }} ₽</span><span class="text-ink-dim">{{ $car->activeOffers->count() }}</span>@endif
                        @if ($bad)<span class="chip bg-urgent-soft text-urgent">{{ $car->specs_state->needsAttention() ? $car->specs_state->label() : $car->photos_state->label() }}</span>@endif
                        @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span class="chip">фото едут</span>@endif
                        @unless ($car->is_published)<span class="chip bg-closed-soft text-closed">скрыта</span>@endunless
                    </div>
                </div>
            </a>
        @endforeach
    </div>
    <div class="mt-4">{{ $cars->links() }}</div>
</x-ui.shell>
