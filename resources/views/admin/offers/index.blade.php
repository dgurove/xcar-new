{{-- Предложения CRM — вкладки по шагам (владелец 04.10.2026): «Без закупочной цены» (модератор), «Без продажной цены»
     (админ; 05.10.2026 «Без цены» разделена надвое) — строки и карточка, к следующей по делу, «Оцененные» (галочки и
     «Отправить в продажу»), «Публикация» (группы по слотам, раньше — выше), «Опубликованные» (группы: выбрать, идёт приём, без подтверждений), «Архив». Галочки — pick_controller,
     форма `offers-pick` вне таблицы: строки к ней приписаны атрибутом form. --}}
@php
    use App\Http\Admin\OfferController;
    use App\Offers\Slots;
    $table = \App\Support\ListView::isTable($view);
    $admin = auth()->user()->canManageCrm();
    // Сколько rem у столбцов кроме названия — чтобы название занимало остальное (x-ui.table :rest).
    // Замерено по живым строкам (04.10.2026); «Вендор, № убытка» — по самому длинному номеру (цифра ≈ .55rem, логотип и
    // поля 3rem), не меньше подписи столбца.
    $refCh = (int) $offers->getCollection()->max(fn ($o) => mb_strlen((string) $o->claim_ref));
    $cityCh = (int) $offers->getCollection()->max(fn ($o) => mb_strlen((string) $o->settlement?->name));
    $restOf = ['vendor' => max(8.5, $refCh * .55 + 3), 'city' => max(5, $cityCh * .5 + 2.5), 'state' => 10, 'bids' => 7, 'value' => 5.5, 'floor' => 5.5, 'price' => 6, 'created' => 7.5, 'published' => 6.5];
    $rest = ($cols === null ? array_sum($restOf) : array_sum(array_intersect_key($restOf, array_flip($cols)))) + ($pick ? 2.5 : 0);
    $span = ($cols === null ? 8 + ($admin ? 1 : 0) : count($cols) + 1 + (in_array('state', $cols, true) ? 0 : 1)) + ($pick ? 1 : 0);
    // Группы: «Публикация» — по слоту, «Опубликованные» у админа — по тому, что делать. Остальное одной группой.
    $groups = match (true) {
        $searching => collect(['' => $offers->getCollection()]),
        $preset === 'slots' => $offers->getCollection()->groupBy(fn ($o) => $o->slot_at->format('YmdHi')),
        $preset === 'published' && $admin => $offers->getCollection()->groupBy(fn ($o) => OfferController::pickGroup($o))->sortKeys(),
        default => collect(['' => $offers->getCollection()]),
    };
    $groupTitle = fn ($key, $list) => match (true) {
        $key === '' => null,
        $preset === 'slots' => $list->first()->slot_at->isPast() ? 'Выходит сейчас' : Slots::label($list->first()->slot_at),
        default => OfferController::PICK_GROUPS[$key],
    };
@endphp
<x-ui.shell title="Предложения" :count="$step ? null : $offers->total()" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :sorts="$sorts" :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :pill-home="$home" :counts="$counts" name="offers" :facets="$facets" search="Номер, марка, VIN, убыток">
        <x-slot:extra>@unless ($searching)<x-ui.view-switch :current="$view"/>@endunless</x-slot:extra>
        <x-slot:actions>
            {{-- «Оценить»: по одной — первая строка вкладки в карточке; из текста — сообщение с оценочными стоимостями. --}}
            @if (in_array($preset, ['nofloor', 'unpriced'], true) && ! $searching)
                <div class="contents" data-controller="menu sheet">
                    <button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="menu#toggle" aria-haspopup="menu" aria-controls="rate-menu">Оценить<x-ui.icon name="chevron-down" class="size-4"/></button>
                    <div id="rate-menu" class="menu" popover data-menu-target="list" role="menu">
                        <a href="/?preset={{ $preset }}&peek=first" class="menu-item" role="menuitem" data-action="menu#close">По одной</a>
                        <button type="button" class="menu-item w-full" role="menuitem" data-action="menu#close sheet#open">Из текста</button>
                    </div>
                    <x-offer.valuation-sheet/>
                </div>
            @endif
            @if (auth()->user()->canCrmMail())<a href="/offers/from-mail" class="btn btn-s btn-quiet relative shrink-0 rounded-full" aria-label="Из писем"><x-ui.icon name="mail" class="size-4"/><span class="hidden sm:inline">Из писем</span><x-ui.badge href="/offers/from-mail" :badges="\App\Support\Nav::badges(auth()->user())"/></a>@endif
            <form method="post" action="/offers" class="shrink-0">@csrf<button type="submit" class="btn btn-s btn-accent rounded-full"><x-ui.icon name="plus" class="size-4"/><span class="hidden sm:inline">Новый</span></button></form>
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="mt-6" id="list" @if ($pick) data-controller="pick" @endif>
        @if ($offers->isEmpty())
            <x-ui.empty>{{ match ($searching ? '' : $preset) { 'nofloor' => 'Закупочные заполнены', 'unpriced' => 'Всё оценено', 'priced' => 'Оценённых нет', 'slots' => 'В слотах пусто', 'published' => 'Опубликованных нет', default => 'Предложений нет' } }}</x-ui.empty>
        @else
            @if ($pick)
                <form id="offers-pick" method="post" action="/offers/schedule">@csrf</form>
                <div class="pick-all"><label class="row-check gap-3"><span class="check"><input type="checkbox" id="pick-all-{{ $preset }}" data-turbo-permanent data-pick-target="all" data-action="pick#pickAll"></span><span>Выбрать все</span></label></div>
            @endif
            @if ($table)
                <x-ui.table id="offers" :view="$view" :titles="$offers->getCollection()->map(fn ($o) => mb_strlen($o->titleWithYear()) + ($o->recommended ? 3 : 0) + (\App\Support\CarLinks::shows($o, auth()->user()) ? 3 : 0) + (blank($o->vin) && in_array($o->state, [\App\Offers\OfferState::Draft, \App\Offers\OfferState::Gallery, \App\Offers\OfferState::Open], true) ? 7 : 0))" :rest="$rest">
                    <x-slot:head><x-offer.table-head :pick="$pick" :cols="$cols"/></x-slot:head>
                    @foreach ($groups as $key => $list)
                        @if ($title = $groupTitle($key, $list))
                            <tr class="table-group"><th colspan="{{ $span }}"><span class="table-group-name">@if ($pick)<label class="row-check" aria-label="Выбрать весь слот"><span class="check"><input type="checkbox" id="pick-group-{{ $key }}" data-turbo-permanent data-pick-target="group" data-group="{{ $key }}" data-action="pick#pickGroup"></span></label>@endif{{ $title }} <span class="nums">{{ $list->count() }}</span></span></th></tr>
                        @endif
                        @foreach ($list as $offer)<x-offer.table-row :offer="$offer" :cols="$cols" :checkable="$pick" :group="$preset === 'slots' ? $key : null"/>@endforeach
                    @endforeach
                </x-ui.table>
            @else
                @foreach ($groups as $key => $list)
                    @if ($title = $groupTitle($key, $list))<h2 class="box-title flex items-center gap-2 {{ $loop->first ? '' : 'mt-6' }}">@if ($pick)<label class="row-check" aria-label="Выбрать весь слот"><span class="check"><input type="checkbox" id="pick-group-{{ $key }}" data-turbo-permanent data-pick-target="group" data-group="{{ $key }}" data-action="pick#pickGroup"></span></label>@endif{{ $title }} <span class="nums text-ink-dim">{{ $list->count() }}</span></h2>@endif
                    <div @if ($loop->first) id="offers" @endif class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
                        @foreach ($list as $offer)<x-offer.crm-card :offer="$offer" :checkable="$pick" :group="$preset === 'slots' ? $key : null"/>@endforeach
                    </div>
                @endforeach
            @endif
            <div class="mt-8"><x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/></div>
            {{-- Сделали последнюю — тост ведёт дальше по шагу (detail_controller#advance); модератору дальше ничего нет. --}}
            @if ($preset === 'unpriced' && ! $searching)
                <a href="/?preset=priced" data-advance-done hidden>Все оценены, дальше «Оцененные»</a>
            @elseif ($preset === 'nofloor' && ! $searching && $admin)
                <a href="/?preset=unpriced" data-advance-done hidden>Закупочные заполнены, дальше «Без продажной цены»</a>
            @elseif ($preset === 'nofloor' && ! $searching)
                <span data-advance-done hidden>Закупочные заполнены</span>
            @endif
            @if ($pick && $preset === 'priced')
                <x-ui.action-bar data-pick-target="bar" hidden>
                    <div class="contents" data-controller="sheet">
                        <x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Отправить в продажу <span class="nums" data-pick-target="count"></span></x-ui.button>
                        <x-offer.publish-sheet id="offers-send" form="offers-pick"/>
                    </div>
                </x-ui.action-bar>
            @elseif ($pick && $preset === 'archive')
                {{-- Архив — навсегда, со всем связанным (кадры, документы, сделки, чаты, счета): только админу, с подтверждением. --}}
                <x-ui.action-bar data-pick-target="bar" hidden>
                    <x-ui.button form="offers-pick" formaction="/offers/purge" variant="danger" class="min-w-0 flex-1" data-turbo-confirm="Удалить навсегда? Фото, документы, сделки, чаты и счета по ним удалятся без возврата" data-turbo-confirm-label="Удалить">Удалить навсегда <span class="nums" data-pick-target="count"></span></x-ui.button>
                </x-ui.action-bar>
            @elseif ($pick)
                <x-ui.action-bar data-pick-target="bar" hidden>
                    <x-ui.button form="offers-pick" formaction="/offers/unschedule" variant="secondary" class="min-w-0 flex-1">Убрать из слота</x-ui.button>
                    <div class="contents" data-controller="sheet">
                        <x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Перенести <span class="nums" data-pick-target="count"></span></x-ui.button>
                        <x-offer.publish-sheet id="offers-move" form="offers-pick" title="Перенести" move/>
                    </div>
                </x-ui.action-bar>
            @endif
        @endif
    </div>
    {{-- Окно писем для карточки «Письма» в карточке строки. --}}
    <x-mail.window/>
</x-ui.shell>
