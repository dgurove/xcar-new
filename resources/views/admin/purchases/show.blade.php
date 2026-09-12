@php use App\Purchases\{PurchaseState, ImportState}; $n = $purchase->number; @endphp
<x-ui.shell :title="$purchase->title ?: $purchase->publicTitle()">
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-2" data-controller="sheet">
        <x-ui.pill :tone="$purchase->state->tone() === 'open' ? 'open' : ($purchase->state->tone() === 'plain' ? 'plain' : 'closed')">{{ $purchase->state->label() }}</x-ui.pill>
        @if ($purchase->offers_close_at)<x-ui.pill tone="plain"><span>до {{ $purchase->offers_close_at->translatedFormat('j M, H:i') }}</span></x-ui.pill>@endif
        @if ($purchase->state->isPublic())<x-ui.pill tone="plain" :href="\App\Support\Surface::Site->url('/zakupki/'.$n)" data-turbo="false">На сайте</x-ui.pill>@endif
        @if ($stats['pending'])<x-ui.pill tone="urgent">выкачка: {{ $stats['pending'] }}</x-ui.pill>@endif
        @if ($errors->any())<x-ui.flash tone="danger" class="w-full">{{ $errors->first() }}</x-ui.flash>@endif
        <button type="button" class="btn btn-s btn-quiet btn-round ml-auto" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
        <x-ui.sheet id="purchase-actions" title="Закупка № {{ $n }}">
            <form method="post" action="/zakupki/{{ $n }}" class="flex flex-col gap-3">
                @csrf @method('put')
                <x-ui.field name="title" label="Название для нас" :value="$purchase->title"/>
                <x-ui.field name="supplier" label="Поставщик" :value="$purchase->supplier"/>
                <x-ui.field name="offers_close_at" label="Цены до" type="datetime-local" :value="$purchase->offers_close_at?->format('Y-m-d\TH:i')"/>
                <x-ui.button block variant="secondary">Сохранить</x-ui.button>
            </form>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($transitions as $next)
                    @if (in_array($next, match($purchase->state) { PurchaseState::Draft => [PurchaseState::Open, PurchaseState::Archived], PurchaseState::Open => [PurchaseState::Closed, PurchaseState::Draft], PurchaseState::Closed => [PurchaseState::Open, PurchaseState::Archived], PurchaseState::Archived => [PurchaseState::Draft] }, true))
                        <form method="post" action="/zakupki/{{ $n }}/sostoyanie">@csrf<input type="hidden" name="state" value="{{ $next->value }}"><x-ui.button block :variant="$next === PurchaseState::Open ? 'primary' : 'secondary'">{{ match($next) { PurchaseState::Open => 'Открыть приём цен', PurchaseState::Closed => 'Закрыть приём', PurchaseState::Draft => 'В черновик', PurchaseState::Archived => 'В архив' } }}</x-ui.button></form>
                    @endif
                @endforeach
                <form method="post" action="/zakupki/{{ $n }}/zanovo">@csrf<input type="hidden" name="specs" value="1"><x-ui.button block variant="ghost">Перечитать характеристики</x-ui.button></form>
                <form method="post" action="/zakupki/{{ $n }}/zanovo">@csrf<input type="hidden" name="photos" value="1"><x-ui.button block variant="ghost">Дозабрать фотографии</x-ui.button></form>
            </div>
        </x-ui.sheet>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([['Машин', $stats['cars']], ['С фото', $stats['photos']], ['С предложениями', $stats['priced']], ['Сумма лучших', $stats['sum'] ? number_format($stats['sum'], 0, '', ' ').' ₽'.($stats['ours'] ? ' · '.($stats['sum'] >= $stats['ours'] ? '+' : '−').number_format(abs($stats['sum'] - $stats['ours']), 0, '', ' ') : '') : '—']] as [$label, $value])
            <x-ui.stat :value="$value" :label="$label"/>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col gap-3">
        <div class="flex gap-2">
            <form method="post" action="/zakupki/{{ $n }}/fayl" enctype="multipart/form-data" class="flex-1" data-controller="autosubmit">
                @csrf
                <label class="btn btn-quiet w-full cursor-pointer"><x-ui.icon name="plus" class="size-5"/> Загрузить xlsx<input type="file" name="file" accept=".xlsx" hidden data-action="change->autosubmit#submit"></label>
            </form>
            @if ($stats['cars'])<a href="/zakupki/{{ $n }}/xlsx" class="btn btn-quiet flex-1" data-turbo="false"><x-ui.icon name="file" class="size-5"/> Выгрузить xlsx</a>@endif
        </div>
        @if ($stats['cars'])
        <x-ui.toolbar :pills="\App\Http\Admin\PurchaseController::PRESETS" :pill="$preset" pill-param="preset" name="purchase">
            <x-slot:filters><input name="q" value="{{ $q }}" placeholder="ДЛ, VIN, марка" class="field-input field-s"></x-slot:filters>
        </x-ui.toolbar>
        @endif
    </div>

    <div class="flex flex-col gap-2">
        @foreach ($cars as $car)
            @php $best = $car->bestOffer(); $bad = $car->specs_state->needsAttention() || $car->photos_state->needsAttention(); @endphp
            <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="row items-start">
                <div class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $car->titleWithYear() }}</span><span class="shrink-0 text-sm text-ink-dim">{{ $car->dl }}</span></div>
                    <div class="text-sm text-ink-muted">{{ implode(' · ', array_filter([$car->price_listing ? number_format($car->price_listing, 0, '', ' ').' ₽' : null, $car->kind->label(), $car->settlement?->name ?? $car->city])) }}</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-sm">
                        @php $active = $car->offers->whereIn('state', [\App\Purchases\OfferState::Active, \App\Purchases\OfferState::Chosen]); @endphp
                        @if ($best)
                            <span class="font-semibold tabular-nums {{ $best->state === \App\Purchases\OfferState::Chosen ? 'text-accent-text' : '' }}">{{ number_format($best->amount, 0, '', ' ') }} ₽</span>
                            <span class="text-ink-muted">{{ $best->user->shortName() }}{{ $best->state === \App\Purchases\OfferState::Chosen ? ' · выбран' : '' }}</span>
                            @if ($active->count() > 1)<span class="text-ink-dim">{{ $active->count() }} {{ \App\Support\Plural::of($active->count(), ['предложение', 'предложения', 'предложений']) }}</span>@endif
                        @else
                            <span class="text-ink-dim">нет предложений</span>
                        @endif
                        @if ($bad)<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">{{ $car->specs_state->needsAttention() ? $car->specs_state->label() : $car->photos_state->label() }}</x-ui.pill>@endif
                        @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span class="chip">фото едут</span>@endif
                        @unless ($car->is_published)<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">скрыта</x-ui.pill>@endunless
                    </div>
                </div>
            </a>
        @endforeach
    </div>
    <div class="mt-8">{{ $cars->links() }}</div>
</x-ui.shell>
