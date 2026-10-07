@php
    use App\Cars\{Body, Transmission, Drive, Fuel, DamageCause, DamageZone, Papers};
    use App\Offers\{OfferState, BidState, InterestState};
    $n = $offer->number;
    // «В гараже» ставит не кнопка состояния, а «Отдать в гараж»: там выбирают менеджера и цену.
    // Кнопки состояния — только уместные (OfferState::actions): в сделке меню нет, «Снять с продажи» — у того, что в продаже.
    // Модератор правит только поля черновика: состояния, гаража, подтверждений и круга показа у него нет.
    $transitions = $admin ? collect($offer->state->actions())->mapWithKeys(fn ($label, $state) => [$state => [OfferState::from($state), $label]]) : collect();
    $garage = $garageView['car'] ?? null;
    // Машину из гаража уводит только «Отдали по ошибке» там же: кнопки состояния тут отбились бы ошибкой.
    if ($garage) $transitions = collect();
    // Руками в гараж — не из сделки: идущую сначала отменяют; отдают как гаражную сделку (`GiveToGarage`), со страховой — маршрутом.
    $canGarage = $admin && ! $garage && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true);
    // Подтверждения: ждущие по сумме вниз, потом решённые.
    $bids = $admin ? $offer->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]) : collect();
    $grid = 'grid grid-cols-2 gap-3 @4xl:grid-cols-3';
    // С публикации поля ТС и цены свёрнуты (владелец 06.10.2026); раскрыты сами, если в них есть что смотреть.
    $carKeys = ['vendor_id', 'claim_ref', 'vin', 'brand_id', 'model_id', 'year', 'mileage', 'color', 'body', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'settlement_id', 'inspection_address', 'description'];
    $moneyKeys = ['value', 'floor_price', 'publish_price', 'asking_price', 'min_bid_price', 'min_bid_share'];
    // Подсветка Мигторга читается один раз (`glowed` забирает её из кэша) — и для формы ниже.
    $glow = \App\Offers\Jobs\ImportMigtorgLot::glowed($offer);
    $migDiff = \App\Offers\MigtorgDiff::of($offer);
    $hints = [...array_keys($migDiff), ...$glow];
    // Сворачивание и «Показ» — от дела, а не от одного состояния: у машины в сделке или гараже ТС и цены свёрнуты всегда
    // (07.10.2026, владелец: «гараж — это тоже как сделка»).
    $published = $offer->state !== OfferState::Draft || $deal || $garage;
    $foldCar = $published && ! $errors->hasAny($carKeys) && ! array_intersect($hints, $carKeys);
    // Чат сделки — карточкой первой в правой колонке (`x-deal.chat-card`, 07.10.2026); в «Чатах» и пилюле — остальные.
    $dealChat = $deal?->buyer;
    $otherChats = $dealChat ? $chats->reject(fn ($c) => $c->user_id === $deal->buyer_id && ! $c->manager_id)->values() : $chats;
    $foldMoney = $published && ($offer->floor_price || $offer->asking_price) && ! $errors->hasAny($moneyKeys) && ! array_intersect($hints, $moneyKeys);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Предложения', '/']" cache="no-cache">
    <x-slot:badge><x-ui.links :offer="$offer"/></x-slot:badge>
    {{-- Под заголовком — только состояние и метки, пилюлями одного роста (номер, убыток и документы — в полях и блоках
         ниже). Метки: выбранные пилюлями, «+» и нажатие на метку — шторка со списком и своей меткой; галки ходят в форму
         оффера через form=, пилюли перерисовывает tag-chips сразу. Срок приёма, чаты и импорт — только когда они есть. --}}
    @php
        $own = $offer->tag_colors ?? [];
        $tagStyle = fn (string $name) => \App\Offers\Tag::style(\App\Offers\Tag::colorOf($name, $own));
    @endphp
    <div class="offer-head -mt-3 mb-4 flex flex-wrap items-center gap-1.5">
        @if ($garage)
            {{-- Отданная в гараж — «в гараже», как в сделке, пока идут и бумаги со страховой (владелец 06.10.2026). --}}
            <x-ui.pill tone="plain" href="#garage">В гараже, {{ $garage->manager?->shortName() ?? 'взяли под себя' }}</x-ui.pill>
        @elseif ($admin && $offer->isScheduled())
            <x-offer.slot-menu :offer="$offer"/>
        @elseif ($admin && $offer->deal)
            {{-- В сделке пилюля состояния ведёт к дорожке «Сделка» ниже. --}}
            <x-ui.pill :tone="$offer->state->tone()" href="#deal">{{ $offer->state->label() }}</x-ui.pill>
        @else
            <x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->labelFor(auth()->user()) }}</x-ui.pill>
        @endif
        @if ($offer->state === \App\Offers\OfferState::Draft && $offer->published_at === null && $offer->parkVehicle)
            <form method="post" action="/offers/{{ $n }}/unlist" class="contents" data-turbo-confirm="Снять с продажи? Черновик удалится, ТС останется на парковке">@csrf<button type="submit" class="pill pill-plain">Снять с продажи</button></form>
        @endif
        {{-- Срок приёма и продление — админу: подтверждения принимает только он. --}}
        @if (! $admin)
        @elseif ($offer->closed())
            <x-ui.pill tone="closed">Приём закрыт с {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</x-ui.pill>
        @elseif ($offer->bids_close_at && $offer->state === OfferState::Open)
            <x-ui.pill tone="plain"><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span></x-ui.pill>
        @endif
        @if ($admin && $offer->state === OfferState::Open && $offer->bids_close_at)
            {{-- Продлить приём на ходу, как в закупке: от текущего срока, если он не прошёл, иначе от сейчас. --}}
            @foreach ([15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
                <form method="post" action="/offers/{{ $n }}/extend" class="contents">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}"><button class="pill pill-plain nums">{{ $label }}</button></form>
            @endforeach
        @endif
        @if ($otherChats->isNotEmpty())<x-ui.pill :tone="$otherChats->sum('unread_for_staff') ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $offer->number }}"><x-ui.icon name="chat" class="size-4"/> {{ $otherChats->count() === 1 ? 'Чат' : 'Чатов: '.$otherChats->count() }}@if ($otherChats->sum('unread_for_staff')) <span class="badge">{{ $otherChats->sum('unread_for_staff') }}</span>@endif</x-ui.pill>@endif
        @if ($admin)
            <div class="contents" data-controller="sheet tag-chips" data-action="change->tag-chips#render">
                <span class="contents" data-tag-chips-target="chips">
                    @foreach ($offer->tags ?? [] as $name)<button type="button" class="pill pill-tag" style="{{ $tagStyle($name) }}" data-action="sheet#open">{{ $name }}</button>@endforeach
                </span>
                <button type="button" class="pill pill-plain" data-action="sheet#open" aria-label="Метки"><x-ui.icon name="plus" class="size-4"/><span data-tag-chips-target="word" @if ($offer->tags) hidden @endif>Метка</span></button>
                <x-ui.sheet id="offer-tags" title="Метки">
                    <div class="flex flex-col gap-4">
                        @include('admin.offers.fields.tags', ['form' => 'offer-form', 'label' => false])
                        <x-ui.button type="button" block data-action="sheet#close">Готово</x-ui.button>
                    </div>
                </x-ui.sheet>
            </div>
            <span class="ml-auto flex items-center gap-1">
                @include('admin.offers.share-button')
            </span>
        @endif
        @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
    </div>

    {{-- Раскладка (06.10.2026, владелец): слева «Транспортное средство», под ним дорожки в ряд (`admin.offers.lanes`:
         «Продажа», «Вывоз», «Гараж» / «Сделка» / «Подтверждения») — в основной колонке, правую не занимают (07.10.2026);
         справа чат, «Цены», «Чаты» и «История»; фото, документы и письма — во всю ширину. На телефоне одной колонкой по order-*: дорожки первыми — в них ход. Колонки — по ширине
         содержимого (@container): с открытой справа шторкой документов редактор в одну колонку. --}}
    <div class="@container">
    <div class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents @4xl:col-start-1 @4xl:row-start-1 @4xl:flex @4xl:min-w-0 @4xl:flex-col @4xl:gap-4">
        <form method="post" action="/offers/{{ $n }}" id="offer-form" data-controller="vin draft save-bar next migtorg-diff glow{{ $empty ? ' drop-empty' : '' }}" data-glow-fields-value="{{ json_encode($glow) }}" data-migtorg-diff-url-value="/offers/{{ $n }}" data-migtorg-diff-fields-value="{{ json_encode((object) $migDiff) }}" data-save-bar-dirty-value="{{ $errors->any() ? 'true' : 'false' }}" @if ($empty) data-drop-empty-url-value="/offers/{{ $n }}/drop-empty" @endif class="order-2 @4xl:order-none">
            @csrf @method('put')

            {{-- С публикации — «Изменить / Готово», как в Контактах iOS (06.10.2026): данные текстом в той же сетке, что поля
                 (`fields.passport`), «Изменить» ставит на их место поля (`edit_card_controller`), «Готово» сохраняет. Поля
                 всё время в форме, скрытые: сохранение «Показа» шлёт их как есть. Черновик — сразу поля; есть ошибки или
                 расхождения Мигторга — тоже, с «Готово». --}}
            <x-ui.card title="Транспортное средство" :data-controller="$published ? 'edit-card' : null" :data-edit-card-editing-value="$published && ! $foldCar ? 'true' : null">
                @if ($published)
                    <x-slot:actions><button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">{{ $foldCar ? 'Изменить' : 'Готово' }}</button></x-slot:actions>
                @endif
                <x-mail.reader-diffs form="offer-form" class="mb-3"/>
                @if ($empty)<div class="mb-3"><x-ui.paste/></div>@endif
                @if ($published)<div data-edit-card-target="view" @unless ($foldCar) hidden @endunless>@include('admin.offers.fields.passport')</div>@endif
                <div @if ($published) data-edit-card-target="edit" @if ($foldCar) hidden @endif @endif>@include('admin.offers.fields.car')</div>
                {{-- Показ — пока машину показывают: в сделке и в гараже он ни о чём (галки при сохранении не трогаются, `_show`). --}}
                @if (! $deal && ! $garage && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true))
                    @include('admin.offers.fields.show', ['summary' => $showingSummary])
                @endif
            </x-ui.card>
        </form>

        {{-- Дорожки — от ширины своей колонки (`@container/lanes`): от 52rem три в линию, уже — две, ещё уже — столбиком. --}}
        @if ($admin)
            <div class="@container/lanes order-1 min-w-0 @4xl:order-none">@include('admin.offers.lanes')</div>
        @endif
        </div>

        <div class="contents @4xl:col-start-2 @4xl:row-start-1 @4xl:flex @4xl:flex-col @4xl:gap-4">
            {{-- Чат с менеджером сделки — верхом колонки, на телефоне самым верхом страницы, как на странице сделки на xcar
                 (07.10.2026, владелец: «чуть ли не самая важная кнопка во время сделки»). --}}
            @if ($dealChat)<x-deal.chat-card :deal="$deal" class="order-first"/>@endif
            {{-- Деньги — справа над «Историей», у всех; поля ходят в форму оффера через form=. На телефоне — сразу под ТС. --}}
            {{-- Цены — так же: суммы текстом, «Изменить» — поля, «Готово» — сохранить (поля привязаны к форме через form=). --}}
            <x-ui.card title="Цены" class="order-3" :data-controller="$published ? 'edit-card' : null" data-edit-card-form-value="offer-form" :data-edit-card-editing-value="$published && ! $foldMoney ? 'true' : null">
                @if ($published)
                    <x-slot:actions><button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">{{ $foldMoney ? 'Изменить' : 'Готово' }}</button></x-slot:actions>
                    <div data-edit-card-target="view" @unless ($foldMoney) hidden @endunless>@include('admin.offers.fields.money-view', ['garageOnly' => (bool) $garageView])</div>
                @endif
                <div @if ($published) data-edit-card-target="edit" @if ($foldMoney) hidden @endif @endif>@include('admin.offers.fields.money', ['form' => 'offer-form', 'withTags' => false, 'garageOnly' => (bool) $garageView])</div>
            </x-ui.card>

            @if ($otherChats->isNotEmpty())
            <x-ui.card title="Чаты" class="order-5">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($otherChats as $chat)
                        <a href="/work/chats/{{ $chat->id }}" class="flex items-center gap-3 py-2">
                            <x-ui.avatar :user="$chat->user" :size="36"/>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2"><span class="truncate {{ $chat->unread_for_staff ? 'font-medium' : '' }}">{{ $chat->displayName() }}</span><span class="ml-auto shrink-0 text-sm text-ink-dim">{{ $chat->last_message_at?->translatedFormat($chat->last_message_at->isToday() ? 'H:i' : 'j M') }}</span></div>
                                <div class="truncate text-sm text-ink-muted">{{ $chat->last_text ? \Illuminate\Support\Str::limit($chat->last_text, 80) : 'Файл' }}</div>
                            </div>
                            @if ($chat->unread_for_staff)<span class="badge">{{ $chat->unread_for_staff }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            {{-- История — последние четыре записи, остальное по «Ещё N»: лента в тридцать строк занимала экран. --}}
            @php $events = $offer->events->take(30); @endphp
            <x-ui.card title="История" class="order-6">
                <div class="flex flex-col gap-3 text-sm">
                    @foreach ($events->take(4) as $e)@include('admin.offers.event', ['event' => $e])@endforeach
                    @if ($events->count() > 4)
                        <details class="group flex flex-col gap-3">
                            <summary class="cursor-pointer list-none text-ink-muted group-open:hidden">Ещё {{ $events->count() - 4 }}</summary>
                            <div class="flex flex-col gap-3">
                                @foreach ($events->slice(4) as $e)@include('admin.offers.event', ['event' => $e])@endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </x-ui.card>
        </div>

        {{-- Кадры письма прикрепляются после «Завести» (ImportThreadFiles): строка хода и заглушки в ряду, attach_controller
             переспрашивает их, пока строка есть. --}}
        <x-ui.card title="Фотографии" class="order-4 @4xl:order-none @4xl:col-span-2" data-controller="attach" data-attach-url-value="/offers/{{ $n }}/attach">
            <x-mail.attach-line :model="$offer"/>
            @include('admin.offers.gallery')
        </x-ui.card>

        <x-ui.card title="Документы" class="order-4 @4xl:order-none @4xl:col-span-2">
            @include('admin.offers.papers-block', ['reader' => true, 'auto' => $fromMail])
        </x-ui.card>

        {{-- Письма — под документами, как в деле ТС: последнее словами, вся переписка и ответ — окном поверх редактора. --}}
        @if ($lastLetter && auth()->user()->canCrmMail())
            <x-ui.card title="Письма" :count="$letters" class="order-4 @4xl:order-none @4xl:col-span-2">
                <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/offers/'.$n.'/letters'" :asks="$asks"/>
            </x-ui.card>
        @endif
    </div>
    </div>

    {{-- Гараж минуя подтверждение (x-offer.garage-form): этап, кому, кто платит поставщику. --}}
    @if ($canGarage)
        <div data-controller="sheet" data-action="garage:open@window->sheet#open" class="contents">
            <x-ui.sheet id="offer-garage" title="Отдать в гараж">
                <x-offer.garage-form :offer="$offer" :managers="$managers"/>
            </x-ui.sheet>
        </div>
    @endif

    {{-- Черновик из писем до первого сохранения: «Отменить» (черновика не было, цепочка снова в «Из писем»), «Не заявка»
         (и цепочку в архив), «Сохранить». Черновик и дальше только «Сохранить»: публикуют из списка по маршруту. --}}
    @php
        $draft = $offer->state === OfferState::Draft;
        // «Опубликовать» — с выбором слота: у черновика шторкой из плашки, у галереи тремя пунктами в «···».
        $publishItems = $admin && $offer->state === OfferState::Gallery && ! $offer->isScheduled();
        if ($draft || $offer->state === OfferState::Gallery) $transitions = $transitions->except(OfferState::Open->value);
    @endphp
    <x-mail.window :url="$window" :title="$offer->titleWithYear()"/>
    @php $deletable = ! $fromMail && $offer->isDeletableBy(auth()->user()); @endphp
    <x-ui.action-bar>
        @if ($fromMail)
            <form method="post" action="/offers/{{ $n }}/drop" class="contents">@csrf<x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Отменить</x-ui.button></form>
            <form method="post" action="/offers/{{ $n }}/drop" class="contents" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<input type="hidden" name="decline" value="1"><x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Не заявка</x-ui.button></form>
            <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @elseif ($draft)
            {{-- Черновик заводят пачкой подряд — и модератор, и админ (владелец 04.10.2026): «Сохранить» сохраняет и закрывает
                 редактор (в список), «+ Новый» сохраняет и открывает следующий черновик с тем же вендором. Публикуют из
                 списка («Оцененные» → «Отправить в продажу»). Модератор свой черновик удаляет корзиной, админ — в «···». --}}
            @if ($deletable && ! $admin)
                <form method="post" action="/offers/{{ $n }}" class="draft-delete contents" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button variant="secondary" round class="btn-lg shrink-0" aria-label="Удалить черновик"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>
            @endif
            <x-ui.button form="offer-form" name="then" value="close" variant="secondary" class="min-w-0 flex-1">Сохранить</x-ui.button>
            <x-ui.button form="offer-form" name="then" value="next" class="min-w-0 flex-1"><x-ui.icon name="plus" class="size-5"/>Новый</x-ui.button>
        @else
            <x-ui.button form="offer-form" class="min-w-0 flex-1" data-save-bar-button data-save-bar="offer-form" hidden>Сохранить изменения</x-ui.button>
        @endif
        @if (($transitions->isNotEmpty() || $publishItems || ($deletable && $admin)) && ! $fromMail)
            {{-- Контроллер шторки — только вместе с ней: у сделки «···» нет, и пустой контроллер падал в консоль. --}}
            <div class="contents" data-controller="sheet">
            <x-ui.button type="button" variant="secondary" round class="btn-lg" data-action="sheet#open" aria-label="Состояние"><x-ui.icon name="more" class="size-6"/></x-ui.button>
            <x-ui.sheet id="offer-actions" title="Предложение № {{ $n }}">
                <div class="flex flex-col gap-2">
                    @if ($publishItems)<x-offer.publish-items :offer="$offer" button/>@endif
                    @foreach ($transitions as [$next, $label])
                        <form method="post" action="/offers/{{ $n }}/state" @if (in_array($next, [OfferState::Archived, OfferState::Cancelled])) data-turbo-confirm="{{ $next->label() }}?" @if ($offer->parkVehicle) data-turbo-confirm-text="ТС снимется с продажи, на парковке она остаётся с фото и документами" @endif @endif>
                            @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                            <x-ui.button block :variant="$next === OfferState::Open ? 'primary' : ($next->tone() === 'danger' || $next === OfferState::Archived ? 'danger' : 'secondary')">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach
                    @if ($canGarage)
                        <x-ui.button type="button" variant="secondary" block data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="garage:open">Отдать в гараж</x-ui.button>
                    @endif
                    @if ($admin && in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true))
                        <form method="post" action="/offers/{{ $n }}/purge" data-turbo-confirm="Удалить навсегда? Фото, документы, сделки, чаты и счета по нему удалятся без возврата" data-turbo-confirm-label="Удалить">@csrf<x-ui.button block variant="danger">Удалить навсегда</x-ui.button></form>
                    @endif
                    @if ($deletable && $admin)
                        <form method="post" action="/offers/{{ $n }}" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button block variant="danger">Удалить черновик</x-ui.button></form>
                    @endif
                </div>
            </x-ui.sheet>
            </div>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
