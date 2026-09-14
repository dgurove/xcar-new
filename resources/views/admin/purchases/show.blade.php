@php use App\Purchases\PurchaseState; $n = $purchase->number; $ctl = \App\Http\Admin\PurchaseController::class; @endphp
<x-ui.shell :title="$purchase->title ?: $purchase->publicTitle()" :heading="false" :back="['Закупки', '/zakupki']">
    <div class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-2" data-controller="sheet">
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
                @if ($counts['all'] ?? $summary?->cars->count() ?? 0)
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

    <x-ui.toolbar :sorts="$ctl::SORTS" :sort="$sort" sort-side="right" :pills="$ctl::VIEWS" :pill="$view" pill-param="view" :hidden="['view' => $view, 'preset' => $preset ?? null, 'user' => $user?->id ?? request('user'), 'has' => isset($has) ? (int) $has : null]" name="purchase">
        <x-slot:filters><input name="q" value="{{ $q }}" placeholder="ДЛ, VIN, марка" class="field-input field-s"></x-slot:filters>
    </x-ui.toolbar>

    @if ($view === 'cars')
        <x-ui.pills class="mt-3">
            @foreach ($ctl::PRESETS as $key => $label)
                <x-ui.pill :href="request()->fullUrlWithQuery(['preset' => $key === 'all' ? null : $key, 'page' => null])" :current="$preset === $key" data-turbo-action="replace">{{ $label }} <span class="nums opacity-70">{{ $counts[$key] }}</span></x-ui.pill>
            @endforeach
        </x-ui.pills>
    @else
        @php $unpriced = $summary->unpriced()->count(); @endphp
        <x-ui.pills class="mt-3">
            @foreach ($summary->managers as $u)
                <x-ui.pill :href="request()->fullUrlWithQuery(['user' => $u->id, 'has' => null, 'page' => null])" :current="$user?->id === $u->id" class="!pl-1.5 gap-1.5" data-turbo-action="replace"><x-ui.avatar :user="$u" :size="22"/>{{ $u->shortName() }} <span class="nums opacity-70">{{ $summary->stats[$u->id]['offered'] }}</span></x-ui.pill>
            @endforeach
            <x-ui.pill :href="request()->fullUrlWithQuery(['user' => 'none', 'has' => null, 'page' => null])" :current="! $user" data-turbo-action="replace">Ничьи <span class="nums opacity-70">{{ $unpriced }}</span></x-ui.pill>
        </x-ui.pills>
        @if ($user)
            @php $st = $summary->stats[$user->id]; @endphp
            <x-ui.pills class="mt-2">
                <x-ui.pill :href="request()->fullUrlWithQuery(['has' => null, 'page' => null])" :current="$has" data-turbo-action="replace">С его ценой <span class="nums opacity-70">{{ $st['offered'] }}</span></x-ui.pill>
                <x-ui.pill :href="request()->fullUrlWithQuery(['has' => 0, 'page' => null])" :current="! $has" data-turbo-action="replace">Без его цены <span class="nums opacity-70">{{ $st['missing'] }}</span></x-ui.pill>
            </x-ui.pills>
        @endif
    @endif

    <div class="mt-4 flex flex-col gap-2">
        @forelse ($cars as $car)
            <x-purchase.crm-row :car="$car" :purchase="$purchase" :highlight="$view === 'managers' ? $user?->id : null" :price="$view === 'cars'"/>
        @empty
            <x-ui.empty>Ничего не нашлось.</x-ui.empty>
        @endforelse
    </div>
    <div class="mt-8">{{ $cars->links() }}</div>
</x-ui.shell>
