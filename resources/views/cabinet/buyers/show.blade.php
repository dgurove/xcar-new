{{-- Покупатель (11.11.2026, переверстано — владелец: «хаос», «„Написать“ — кому ты писать собирался»): как контакт в
     телефоне — шапка (кружок, имя, номер своей строкой, под ним «Покупатель с доступом с 11.11»), без кружков действий,
     дальше группы строк под маленькими заголовками. Действие в списке — строкой с лаймовым кружком,
     как «Новая группа»; отдельных кнопок между блоками и кружков «на всякий случай» нет.
     «Доступ к предложениям»: без доступа — «Дать доступ»; с доступом — «Видит», «Пароль», «Закрыть доступ».
     «Для сделок»: паспорт и группы. Ниже интерес и чаты; у «выбранных» — открытое и «Открыть предложения» внизу. --}}
@php
    $me = auth()->user();
    $link = ($link['user'] ?? null) === $buyer->id ? $link : null;
    $access = $buyer->hasBuyerAccess();
    $party = $buyer->party;
    $ready = $party?->readyAsBuyer();
    $passportStatus = match (true) { (bool) $ready => 'заполнен', (bool) $party?->readyForContract() => 'нет скана', default => 'не заполнен' };
@endphp
<x-ui.cabinet :title="$buyer->name" heading="Покупатели" :back="$back" :phone-heading="false">
<div class="mx-auto flex max-w-2xl flex-col gap-6">
    <x-ui.contact :name="$buyer->name" :user="$buyer">
        {{-- Строками, а не через запятую (владелец 11.11.2026): номер — своей строкой (нажатие — «Скопировать / Позвонить»),
             под ним кто он и с какого дня. Кнопок «Позвонить» и «Чат» нет: номер и так нажимается, чаты — строками ниже. --}}
        <x-slot:chips>
            <div class="flex flex-col gap-0.5">
                @if ($buyer->phone)<x-ui.tel :value="$buyer->phone" class="self-start"/>@endif
                <span class="text-sm text-ink-muted">Покупатель {{ $access ? 'с доступом' : 'без доступа' }} с {{ \App\Support\Ago::day($buyer->created_at) }}</span>
            </div>
        </x-slot:chips>
    </x-ui.contact>

    {{-- Доступ к предложениям (`User::hasBuyerAccess`). --}}
    <section>
        <h2 class="list-head">Доступ к предложениям</h2>
        @unless ($access)
            <div data-controller="sheet" class="list">
                <x-ui.sheet-row>
                    <x-ui.row-icon name="key" tone="accent"/>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">Дать доступ</span>
                        <span class="row-sub">Сейчас только данные для сделок, войти не может</span>
                    </span>
                    <x-ui.chevron/>
                </x-ui.sheet-row>
                <x-ui.sheet id="buyer-access" title="Дать доступ">
                    <form method="post" action="/buyers/{{ $buyer->id }}/access" class="flex flex-col gap-4">
                        @csrf
                        <p class="text-ink-muted">{{ $buyer->shortName() }} войдёт в XCar и увидит ваши предложения. Пароль покажем после — отправите ему.</p>
                        <div class="fields">
                            @unless ($buyer->hasSignInContact())
                                <x-ui.field name="contact" label="Телефон или почта" autocomplete="off" autocapitalize="off" required/>
                            @endunless
                            <x-ui.field name="buyer_sees" label="Видит" :options="['all' => 'Все предложения', 'picked' => 'Выбранные']" :value="old('buyer_sees', $buyer->buyer_sees === 'picked' ? 'picked' : 'all')"/>
                        </div>
                        <x-ui.sheet-foot>Дать доступ</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        @else
            <div class="list">
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet-row>
                        <x-ui.row-icon name="eye"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Видит</span>
                            <span class="row-sub nums">{{ $seesAll ? 'Все предложения' : 'Выбранные' }}, {{ $seenCount }}{{ $seesAll && $buyer->buyer_show_vin ? ', с VIN' : '' }}</span>
                        </span>
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="buyer-sees" title="Что видит">
                        <form method="post" action="/buyers/{{ $buyer->id }}/sees" class="flex flex-col gap-4" data-controller="reveal">
                            @csrf @method('put')
                            <x-ui.segment name="buyer_sees" :options="['all' => 'Все предложения', 'picked' => 'Выбранные']" :value="$seesAll ? 'all' : 'picked'" pick/>
                            <div data-reveal-target="pane" data-reveal-key="all" @unless ($seesAll) hidden @endunless>
                                <div class="list">
                                    <label class="row row-check">
                                        <span class="min-w-0 flex-1">Показывать VIN</span>
                                        <span class="check"><input type="checkbox" name="buyer_show_vin" value="1" @checked($buyer->buyer_show_vin)></span>
                                    </label>
                                </div>
                            </div>
                            <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                        </form>
                    </x-ui.sheet>
                </div>
                <form method="post" action="/buyers/{{ $buyer->id }}/password" class="contents"
                    data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
                    data-turbo-confirm-text="{{ $buyer->shortName() }} откроет её и придумает новый пароль. Действует сутки, один раз.">
                    @csrf
                    <button type="submit" class="row w-full text-left">
                        <x-ui.row-icon name="link"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Пароль</span>
                            <span class="row-sub">Выдать ссылку для нового</span>
                        </span>
                        <x-ui.chevron/>
                    </button>
                </form>
                <form method="post" action="/buyers/{{ $buyer->id }}/access" class="contents"
                    data-turbo-confirm="Закрыть доступ?" data-turbo-confirm-label="Закрыть"
                    data-turbo-confirm-text="{{ $buyer->shortName() }} больше не сможет войти и не увидит предложения. Данные для сделок останутся, доступ можно открыть снова.">
                    @csrf @method('delete')
                    <button type="submit" class="row w-full text-left">
                        <x-ui.row-icon name="eye-off" tone="danger"/>
                        <span class="min-w-0 flex-1 font-medium text-danger">Закрыть доступ</span>
                    </button>
                </form>
            </div>
            <x-ui.sheet id="password-link" title="Ссылка для нового пароля" :open="$link !== null">
                @if ($link)
                    <x-ui.share-link :url="$link['url']" title="Новый пароль на xcar" show-url>
                        <x-slot:below><p class="flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">{{ $buyer->shortName() }}, логин <span class="nums font-medium text-ink">{{ $buyer->loginLabel() }}</span></p></x-slot:below>
                    </x-ui.share-link>
                    <x-users.link-mail :user="$buyer" :url="$link['url']" class="mt-3"/>
                @endif
            </x-ui.sheet>
            @unless ($seesAll)
                {{-- «Выбранные»: что открыто — списком, «Открыть предложения» — в полосе внизу. --}}
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet id="pick" title="Открыть {{ $buyer->shortName() }}" wide>
                        <turbo-frame id="pick-frame" src="/buyers/showings/pick?user={{ $buyer->id }}" loading="lazy" class="block min-h-40">
                            <x-ui.skeleton :rows="3"/>
                        </turbo-frame>
                    </x-ui.sheet>
                    @if ($offers->isNotEmpty())
                        <h3 class="list-head mt-4">Открыто</h3>
                        <div class="list">
                            @foreach ($offers as $offer)
                                @include('cabinet.buyers.offer-row', [
                                    'offer' => $offer,
                                    'via' => $via[$offer->id] ?? collect(),
                                    'hide' => in_array($offer->id, $direct, true) ? ['user' => $buyer->id] : null,
                                    'hideText' => $buyer->shortName().' больше не увидит это предложение.',
                                ])
                            @endforeach
                        </div>
                    @endif
                    <x-ui.action-bar>
                        <button type="button" class="btn btn-accent min-w-0 flex-1 md:flex-none" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Открыть предложения</button>
                    </x-ui.action-bar>
                </div>
            @endunless
        @endunless
    </section>

    {{-- Для сделок: паспорт для ДКП (05.10.2026; строкой со шторкой — 11.10.2026) и группы. --}}
    <section>
        <h2 class="list-head">Для сделок</h2>
        <div class="list">
            <div data-controller="sheet" class="contents">
                <x-ui.sheet-row>
                    <x-ui.row-icon name="file"/>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">Паспорт</span>
                        <span @class(['row-sub', 'text-open' => $ready])>{{ $passportStatus }}</span>
                    </span>
                    <x-ui.chevron/>
                </x-ui.sheet-row>
                {{-- Заведён с паспортом («Новый покупатель» → `?passport=1`, 07.11.2026) — шторка сразу открыта: скан читается и заполняет поля. --}}
                <x-ui.sheet id="passport" title="Паспорт" :open="request()->boolean('passport')">
                    <x-billing.passport-scan :party="$party" form="buyer-passport-form"/>
                    <form method="post" action="/buyers/{{ $buyer->id }}/passport" class="flex flex-col gap-3" id="buyer-passport-form">
                        @csrf @method('put')
                        <x-billing.passport-fields :party="$buyer->party ?? new \App\Billing\Party(['name' => $buyer->name])" prefix="buyer" rows/>
                        <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
            @if ($groups->isNotEmpty())
                <div data-controller="sheet" class="contents">
                    <x-ui.sheet-row>
                        <x-ui.row-icon name="users"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Группы</span>
                            <span class="row-sub truncate">{{ $buyer->groups->pluck('name')->implode(', ') ?: 'Не в группах' }}</span>
                        </span>
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="buyer-groups" title="Группы">
                        <form method="post" action="/buyers/{{ $buyer->id }}/groups" class="list" data-controller="autosubmit">
                            @csrf @method('put')
                            <input type="hidden" name="groups[]" value="" disabled>
                            @foreach ($groups as $g)
                                <label class="row row-check"><x-ui.row-icon name="users" size="s"/><span class="min-w-0 flex-1 truncate">{{ $g->name }}</span><span class="check"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked($buyer->groups->contains('id', $g->id)) data-action="change->autosubmit#submit"></span></label>
                            @endforeach
                        </form>
                    </x-ui.sheet>
                </div>
            @endif
        </div>
    </section>

    @if ($interests->isNotEmpty())
        <section>
            <h2 class="list-head">Интерес</h2>
            <div class="list">
                @foreach ($interests as $interest)
                    @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => false])
                @endforeach
            </div>
        </section>
    @endif
    @if ($chats->isNotEmpty())
        <section>
            <h2 class="list-head">Чаты</h2>
            <div class="list">
                @foreach ($chats as $chat)
                    <a href="/deals/chats/{{ $chat->id }}" class="row">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2"><span class="truncate {{ $chat->unread_for_staff ? 'font-medium' : '' }}">{{ $chat->offer?->titleWithYear() ?? 'Обращение' }}</span><span class="ml-auto shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($chat->last_message_at, short: true) }}</span></div>
                            <div class="truncate text-sm {{ $chat->unread_for_staff ? '' : 'text-ink-muted' }}">{{ $chat->last_text ? \Illuminate\Support\Str::limit($chat->last_text, 90) : 'Файл' }}</div>
                        </div>
                        @if ($chat->unread_for_staff)<span class="badge">{{ $chat->unread_for_staff }}</span>@endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-ui.cabinet>
