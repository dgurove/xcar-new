@php use App\Purchases\PurchaseState; $n = $purchase->number; $ctl = \App\Http\Admin\PurchaseController::class; @endphp
<x-ui.shell :title="$purchase->title ?: $purchase->publicTitle()" :back="['Закупки', '/zakupki']">
    <div class="-mt-3 mb-5 flex items-center gap-2" data-controller="sheet">
        <div class="flex min-w-0 flex-1 gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <x-ui.pill :tone="$purchase->state->tone() === 'open' ? 'open' : ($purchase->state->tone() === 'plain' ? 'plain' : 'closed')" class="shrink-0">{{ $purchase->state->label() }}</x-ui.pill>
            @if ($purchase->offers_close_at)<x-ui.pill tone="plain" class="shrink-0"><span>до {{ $purchase->offers_close_at->translatedFormat('j M, H:i') }}</span></x-ui.pill>@endif
            @if ($purchase->state->isPublic())<x-ui.pill tone="plain" class="shrink-0" :href="\App\Support\Surface::Site->url('/zakupki/'.$n)" data-turbo="false">На сайте</x-ui.pill>@endif
            @if ($pending)<x-ui.pill tone="urgent" class="shrink-0">выкачка: {{ $pending }}</x-ui.pill>@endif
        </div>
        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
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
                <form method="post" action="/zakupki/{{ $n }}/fayl" enctype="multipart/form-data" data-controller="autosubmit">
                    @csrf
                    <label class="btn btn-quiet w-full cursor-pointer"><x-ui.icon name="plus" class="size-5"/> Загрузить файл поставщика<input type="file" name="file" accept=".xlsx" hidden data-action="change->autosubmit#submit"></label>
                </form>
                @if ($counts['all'] ?? $summary?->cars->count() ?? 0)
                    <a href="/zakupki/{{ $n }}/xlsx" class="btn btn-quiet w-full" data-turbo="false"><x-ui.icon name="file" class="size-5"/> Выгрузить xlsx поставщику</a>
                    <a href="/zakupki/{{ $n }}/predlozheniya/xlsx" class="btn btn-quiet w-full" data-turbo="false"><x-ui.icon name="file" class="size-5"/> Выгрузить предложения xlsx</a>
                @endif
                <form method="post" action="/zakupki/{{ $n }}/zanovo">@csrf<input type="hidden" name="specs" value="1"><x-ui.button block variant="ghost">Перечитать характеристики</x-ui.button></form>
                <form method="post" action="/zakupki/{{ $n }}/zanovo">@csrf<input type="hidden" name="photos" value="1"><x-ui.button block variant="ghost">Дозабрать фотографии</x-ui.button></form>
            </div>
        </x-ui.sheet>
    </div>
    @if ($errors->any())<x-ui.flash tone="danger" class="mb-4">{{ $errors->first() }}</x-ui.flash>@endif

    <x-ui.toolbar :sorts="$view === 'cars' ? $ctl::SORTS : []" :sort="$sort ?? ''" :pills="$ctl::VIEWS" :pill="$view" pill-param="view" :hidden="['view' => $view, 'preset' => $preset ?? null, 'user' => $user?->id ?? request('user'), 'has' => isset($has) ? (int) $has : null]" name="purchase">
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
                <x-ui.pill :href="request()->fullUrlWithQuery(['user' => $u->id, 'has' => null, 'page' => null])" :current="$user?->id === $u->id" data-turbo-action="replace">{{ $u->shortName() }} <span class="nums opacity-70">{{ $summary->stats[$u->id]['offered'] }}</span></x-ui.pill>
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
            <x-purchase.crm-row :car="$car" :purchase="$purchase" :highlight="$view === 'managers' ? $user?->id : null"/>
        @empty
            <x-ui.empty>Ничего не нашлось.</x-ui.empty>
        @endforelse
    </div>
    <div class="mt-8">{{ $cars->links() }}</div>
</x-ui.shell>
