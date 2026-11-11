{{-- Покупатель — как контакт в телефоне: кружок, имя, чипы, ряд действий (позвонить, написать, пароль).
     Ниже — доступ к предложениям (есть / нет, 11.11.2026) и что видит: строка режима («Все предложения» / «Выбранные»), у выбранных — строки показов (прямой снимается
     смахиванием); интерес, чаты, паспорт строкой. Группы — действие «Группы» в том же ряду.
     Главное действие «выбранных» — «Открыть предложения» — в полосе внизу. --}}
@php $me = auth()->user(); $link = ($link['user'] ?? null) === $buyer->id ? $link : null; @endphp
<x-ui.cabinet :title="$buyer->name" :back="$back" :phone-heading="false">

    <div class="grid gap-6 lg:grid-cols-[minmax(0,44rem)_18rem]">
        <div class="lg:col-start-2 lg:row-start-1" data-controller="sheet">
            <x-ui.contact :name="$buyer->name" :user="$buyer" sidebar>
                <x-slot:chips>
                    {{-- Группы — чипами (ссылками), логин и с какого дня — строкой текста. --}}
                    @foreach ($buyer->groups as $g)<a href="/buyers/groups/{{ $g->id }}" class="tag">{{ $g->name }}</a>@endforeach
                    <div class="detail-line basis-full !mt-0.5 text-sm text-ink-muted lg:justify-center">@if ($buyer->login)<span class="nums">{{ $buyer->login }}</span>@endif @if ($buyer->phone)<x-ui.phone :user="$buyer"/>@endif<span class="nums">с {{ \App\Support\Ago::html($buyer->created_at, words: false) }}</span></div>
                </x-slot:chips>
                <x-slot:acts>
                    @if ($buyer->email)<a href="mailto:{{ $buyer->email }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="mail"/></span>Написать</a>@endif
                    @if ($groups->isNotEmpty())
                        <div data-controller="sheet" class="contents">
                            <button type="button" class="act" data-action="sheet#open"><span class="btn btn-quiet btn-round"><x-ui.icon name="users"/></span>Группы</button>
                            <x-ui.sheet id="buyer-groups" title="Группы">
                                <form method="post" action="/buyers/{{ $buyer->id }}/groups" class="list" data-controller="autosubmit">
                                    @csrf @method('put')
                                    <input type="hidden" name="groups[]" value="" disabled>
                                    @foreach ($groups as $g)
                                        <label class="row row-check"><span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-3"><x-ui.icon name="users" class="size-5"/></span><span class="min-w-0 flex-1 truncate">{{ $g->name }}</span><span class="check"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked($buyer->groups->contains('id', $g->id)) data-action="change->autosubmit#submit"></span></label>
                                    @endforeach
                                </form>
                            </x-ui.sheet>
                        </div>
                    @endif
                    {{-- Пароль — тому, у кого есть доступ; дать доступ — строкой «Без доступа» ниже (11.11.2026). --}}
                    @if ($buyer->hasBuyerAccess())
                        <form method="post" action="/buyers/{{ $buyer->id }}/password" class="contents"
                            data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
                            data-turbo-confirm-text="{{ $buyer->shortName() }} откроет её и придумает новый пароль. Действует сутки, один раз.">
                            @csrf
                            <button type="submit" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="key"/></span>Пароль</button>
                        </form>
                    @endif
                </x-slot:acts>
            </x-ui.contact>
            <x-ui.sheet id="password-link" title="Ссылка для нового пароля" :open="$link !== null">
                @if ($link)
                    <x-ui.share-link :url="$link['url']" title="Новый пароль на xcar" show-url>
                        <x-slot:below><p class="flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">{{ $buyer->shortName() }}, логин <span class="nums font-medium text-ink">{{ $buyer->loginLabel() }}</span></p></x-slot:below>
                    </x-ui.share-link>
                    <x-users.link-mail :user="$buyer" :url="$link['url']" class="mt-3"/>
                @endif
            </x-ui.sheet>
        </div>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            {{-- Доступ к предложениям (11.11.2026, владелец: «покупатели с доступом и без доступа»; `User::hasBuyerAccess`) —
                 первой строкой. С доступом — что видит: все ваши предложения или выбранные (12.10.2026), шторка — режим, VIN и
                 «Закрыть доступ» (войти не сможет, данные для сделок останутся). Без доступа — «только данные для сделок», шторка
                 «Дать доступ»: телефон или почта, если их нет, и что видит; пароль — шторкой входа. --}}
            @unless ($buyer->hasBuyerAccess())
            {{-- Без доступа: строка — только состояние (без действия справа), кнопка — своей строкой ниже (владелец 11.11.2026:
                 «не пихать кнопку справа от заголовка», «кнопка — кнопкой, а не текстом»). --}}
            <section data-controller="sheet" class="flex flex-col gap-3">
                <div class="list">
                    <div class="row">
                        <x-ui.row-icon name="eye-off"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Без доступа к предложениям</span>
                            <span class="row-sub">Только данные для сделок, войти не может</span>
                        </span>
                    </div>
                </div>
                <x-ui.button type="button" variant="secondary" block data-action="sheet#open"><x-ui.icon name="eye" class="size-5"/> Дать доступ</x-ui.button>
                <x-ui.sheet id="buyer-access" title="Доступ к предложениям">
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
            </section>
            @else
            <section data-controller="sheet">
                <div data-controller="sheet" class="list">
                    <x-ui.sheet-row>
                        <x-ui.row-icon name="eye"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Доступ к предложениям</span>
                            <span class="row-sub"><span>{{ $seesAll ? 'все ваши' : 'выбранные' }}</span><span class="nums">{{ $seenCount }} {{ \App\Support\Plural::of($seenCount, ['предложение', 'предложения', 'предложений']) }}</span>@if ($seesAll && $buyer->buyer_show_vin)<span>с VIN</span>@endif</span>
                        </span>
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                    <x-ui.sheet id="buyer-sees" title="Доступ к предложениям">
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
                        <form method="post" action="/buyers/{{ $buyer->id }}/access" class="mt-2"
                            data-turbo-confirm="Закрыть доступ?" data-turbo-confirm-label="Закрыть"
                            data-turbo-confirm-text="{{ $buyer->shortName() }} больше не сможет войти и не увидит предложения. Данные для сделок останутся, доступ можно открыть снова.">
                            @csrf @method('delete')
                            <x-ui.button variant="danger" block>Закрыть доступ</x-ui.button>
                        </form>
                    </x-ui.sheet>
                </div>
                @unless ($seesAll)
                    <x-ui.sheet id="pick" title="Открыть {{ $buyer->shortName() }}" wide>
                        <turbo-frame id="pick-frame" src="/buyers/showings/pick?user={{ $buyer->id }}" loading="lazy" class="block min-h-40">
                            <x-ui.skeleton :rows="3"/>
                        </turbo-frame>
                    </x-ui.sheet>
                    {{-- Пусто — строка режима и так говорит «0 предложений». --}}
                    @if ($offers->isNotEmpty())
                        <div class="list mt-4">
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
                @endunless
            </section>
            @endunless

            @if ($interests->isNotEmpty())
                <section class="mt-6">
                    <h2 class="list-head">Интерес</h2>
                    <div class="list">
                        @foreach ($interests as $interest)
                            @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => false])
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($chats->isNotEmpty())
                <section class="mt-6">
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
            {{-- Паспорт — для ДКП, когда покупатель покупает у страхователя напрямую (05.10.2026). Строкой со шторкой, а не
                 формой во весь экран (11.10.2026: восемь полей стояли посреди страницы между «Видит» и интересом). --}}
            @php
                $party = $buyer->party;
                $ready = $party?->readyAsBuyer();
                $status = match (true) { (bool) $ready => 'заполнен', (bool) $party?->readyForContract() => 'нет скана', default => 'не заполнен' };
            @endphp
            <section class="mt-6" data-controller="sheet">
                <div class="list">
                    <x-ui.sheet-row>
                        <x-ui.row-icon name="file"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Паспорт</span>
                            <span @class(['row-sub', 'text-open' => $ready])>{{ $status }}</span>
                        </span>
                        <x-ui.chevron/>
                    </x-ui.sheet-row>
                </div>
                {{-- Заведён с паспортом («Новый покупатель» → `?passport=1`, 07.11.2026) — шторка сразу открыта: скан читается и заполняет поля. --}}
                <x-ui.sheet id="passport" title="Паспорт" :open="request()->boolean('passport')">
                    <x-billing.passport-scan :party="$party" form="buyer-passport-form"/>
                    <form method="post" action="/buyers/{{ $buyer->id }}/passport" class="flex flex-col gap-3" id="buyer-passport-form">
                        @csrf @method('put')
                        <x-billing.passport-fields :party="$buyer->party ?? new \App\Billing\Party(['name' => $buyer->name])" prefix="buyer" rows/>
                        <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </section>
        </div>
    </div>
</x-ui.cabinet>
