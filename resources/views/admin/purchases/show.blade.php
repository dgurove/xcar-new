@php use App\Purchases\{Kind, PurchaseState}; $n = $purchase->number; $ctl = \App\Http\Admin\PurchaseController::class; @endphp
<x-ui.shell :title="$purchase->title ?: $purchase->publicTitle()" :heading="false" :back="['Закупки', '/zakupki']">
    <div class="has-back mb-5 flex flex-wrap items-center gap-x-3 gap-y-2" data-controller="sheet">
        <x-ui.back :back="['Закупки', '/zakupki']"/>
        <h1 class="text-[28px] sm:text-[34px]">{{ $purchase->title ?: $purchase->publicTitle() }}</h1>
        <button type="button" class="btn btn-s btn-quiet btn-round ml-auto shrink-0 sm:order-1" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
        <span class="flex flex-wrap items-center gap-1.5">
            @if ($purchase->closed())
                <x-ui.pill tone="closed">Приём закрыт с {{ $purchase->offers_close_at->translatedFormat('j M, H:i') }}</x-ui.pill>
            @else
                <x-ui.pill :tone="$purchase->state->tone() === 'open' ? 'open' : ($purchase->state->tone() === 'plain' ? 'plain' : 'closed')">{{ $purchase->state->label() }}{{ $purchase->state === PurchaseState::Open && $purchase->offers_close_at ? ' до '.$purchase->offers_close_at->translatedFormat('j M, H:i') : '' }}</x-ui.pill>
            @endif
            @if ($purchase->state === PurchaseState::Open && $purchase->offers_close_at)
                {{-- Продлить приём на ходу: срок считается от текущего, если он ещё не прошёл, иначе от сейчас. --}}
                <span class="flex shrink-0 items-center gap-1.5">
                    @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                        <form method="post" action="/zakupki/{{ $n }}/prodlit" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
                    @endforeach
                </span>
            @endif
        </span>
        @if ($pending)<x-ui.pill tone="urgent">выкачка: {{ $pending }}</x-ui.pill>@endif
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
                    @if (in_array($next, match($purchase->state) { PurchaseState::Draft => [PurchaseState::Open, PurchaseState::Archived], PurchaseState::Open => [PurchaseState::Draft, PurchaseState::Archived], PurchaseState::Archived => [PurchaseState::Draft] }, true))
                        <form method="post" action="/zakupki/{{ $n }}/sostoyanie">@csrf<input type="hidden" name="state" value="{{ $next->value }}"><x-ui.button block :variant="$next === PurchaseState::Open ? 'primary' : 'secondary'">{{ match($next) { PurchaseState::Open => 'Открыть приём цен', PurchaseState::Draft => 'В черновик', PurchaseState::Archived => 'В архив' } }}</x-ui.button></form>
                    @endif
                @endforeach
                <form method="post" action="/zakupki/{{ $n }}/fayl" enctype="multipart/form-data" data-controller="autosubmit">
                    @csrf
                    <label class="btn btn-quiet w-full cursor-pointer"><x-ui.icon name="plus" class="size-5"/> Загрузить файл поставщика<input type="file" name="file" accept=".xlsx" hidden data-action="change->autosubmit#submit"></label>
                </form>
                @if ($purchase->cars()->exists())
                    {{-- Файл — через file: в установленном приложении download открывает Quick Look без выхода, системный лист закрывается. --}}
                    <form method="get" action="/zakupki/{{ $n }}/vygruzka" class="flex flex-col gap-3 rounded-(--radius-m) bg-surface-2 p-3" data-turbo="false" data-controller="file" data-action="submit->file#share">
                        <span class="text-sm text-ink-dim">Выгрузка поставщику</span>
                        <div class="flex flex-col gap-2 text-sm">
                            @foreach (\App\Purchases\Export::PARTS as $key => $label)
                                <x-ui.check name="parts[]" :value="$key" :checked="$key !== 'managers'">{{ $label }}</x-ui.check>
                            @endforeach
                        </div>
                        <div class="flex gap-2">
                            <button name="format" value="xlsx" class="btn btn-quiet flex-1"><x-ui.icon name="file" class="size-5"/> Excel</button>
                            <button name="format" value="pdf" class="btn btn-quiet flex-1"><x-ui.icon name="file" class="size-5"/> PDF</button>
                        </div>
                        <button name="format" value="dl" class="btn btn-quiet w-full" data-file-any><x-ui.icon name="file" class="size-5"/> Упрощённая</button>
                    </form>
                @endif
            </div>
        </x-ui.sheet>
    </div>
    @if ($errors->any())<x-ui.flash tone="danger" class="mb-4">{{ $errors->first() }}</x-ui.flash>@endif

    @php $kindPills = count($kinds) > 1 ? ['' => 'Все'] + collect(Kind::cases())->filter(fn ($k) => isset($kinds[$k->value]))->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all() : []; @endphp
    <x-ui.toolbar :sorts="$ctl::SORTS" :sort="$sort" sort-side="right" :pills="$kindPills" :pill="$kind?->value ?? ''" pill-param="kind" :counts="['' => array_sum($kinds)] + $kinds" :hidden="['preset' => $preset, 'kind' => $kind?->value, 'user' => $user?->id]" name="purchase">
        <x-slot:filters><input name="q" value="{{ $q }}" placeholder="ДЛ, VIN, марка" class="field-input field-s"></x-slot:filters>
    </x-ui.toolbar>

    {{-- Состояние и менеджер — по одному выбору, сочетаются с типом и поиском; менеджер выбран — состояния про его цену. --}}
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <x-ui.choose name="preset" :options="$presets" :groups="$groups" :value="$preset" default="all" :counts="$counts" title="Какие машины" id="preset-purchase"/>
        @if ($managers->isNotEmpty())
            <x-ui.choose name="user" :options="['' => 'Все менеджеры'] + $managers->mapWithKeys(fn ($u) => [$u->id => $u->shortName()])->all()" :value="$user?->id ?? ''" default="" :counts="$offered->all()" title="Менеджер" id="user-purchase"/>
        @endif
    </div>

    <div class="mt-4 flex flex-col gap-2">
        @forelse ($cars as $car)
            <x-purchase.crm-row :car="$car" :purchase="$purchase" :highlight="$user?->id" price/>
        @empty
            <x-ui.empty>Ничего не нашлось.</x-ui.empty>
        @endforelse
    </div>
    <div class="mt-8">{{ $cars->links() }}</div>
</x-ui.shell>
