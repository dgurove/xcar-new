{{-- Покупатель — как контакт в телефоне: кружок, имя, чипы, ряд действий (позвонить, написать, пароль).
     Ниже — что видит (строки, прямой показ снимается смахиванием) и интерес. Группы — действие «Группы» в том же ряду.
     Главное действие — «Открыть предложения» — в полосе внизу. --}}
@php $me = auth()->user(); $link = ($link['user'] ?? null) === $buyer->id ? $link : null; @endphp
<x-ui.cabinet :title="$buyer->name">

    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="lg:col-start-2 lg:row-start-1" data-controller="sheet">
            <x-ui.contact :name="$buyer->name" :user="$buyer" sidebar>
                <x-slot:chips>
                    @if ($buyer->login)<span class="tag nums">{{ $buyer->login }}</span>@endif
                    <span class="tag nums">с {{ $buyer->created_at->translatedFormat('j M') }}</span>
                    @foreach ($buyer->groups as $g)<a href="/lk/pokupateli/gruppy/{{ $g->id }}" class="tag">{{ $g->name }}</a>@endforeach
                </x-slot:chips>
                <x-slot:acts>
                    @if ($buyer->phone)<a href="tel:+{{ $buyer->phone }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="phone"/></span>Позвонить</a>@endif
                    @if ($buyer->email)<a href="mailto:{{ $buyer->email }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="mail"/></span>Написать</a>@endif
                    @if ($groups->isNotEmpty())
                        <div data-controller="sheet" class="contents">
                            <button type="button" class="act" data-action="sheet#open"><span class="btn btn-quiet btn-round"><x-ui.icon name="users"/></span>Группы</button>
                            <x-ui.sheet id="buyer-groups" title="Группы">
                                <form method="post" action="/lk/pokupateli/{{ $buyer->id }}/gruppy" class="flex flex-col gap-2" data-controller="autosubmit">
                                    @csrf @method('put')
                                    <input type="hidden" name="groups[]" value="" disabled>
                                    @foreach ($groups as $g)
                                        <label class="row row-check"><span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-3"><x-ui.icon name="users" class="size-5"/></span><span class="min-w-0 flex-1 truncate">{{ $g->name }}</span><span class="check"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked($buyer->groups->contains('id', $g->id)) data-action="change->autosubmit#submit"></span></label>
                                    @endforeach
                                </form>
                            </x-ui.sheet>
                        </div>
                    @endif
                    <form method="post" action="/lk/pokupateli/{{ $buyer->id }}/parol" class="contents"
                        data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
                        data-turbo-confirm-text="{{ $buyer->shortName() }} откроет её и придумает новый пароль. Действует сутки, один раз.">
                        @csrf
                        <button type="submit" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="key"/></span>Пароль</button>
                    </form>
                </x-slot:acts>
            </x-ui.contact>
            <x-ui.sheet id="password-link" title="Ссылка для нового пароля" :open="$link !== null">
                @if ($link)
                    <x-ui.copy-link :url="$link['url']" title="Новый пароль на xcar">
                        <p class="text-sm text-ink-muted">Отправьте её {{ $buyer->shortName() }}. Логин для входа — <span class="nums">{{ $buyer->loginLabel() }}</span>.</p>
                    </x-ui.copy-link>
                @endif
            </x-ui.sheet>
        </div>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            <section data-controller="sheet">
                <h2 class="text-xl">Видит @if ($offers->isNotEmpty())<span class="nums text-ink-dim">{{ $offers->count() }}</span>@endif</h2>
                <x-ui.sheet id="pick" title="Открыть {{ $buyer->shortName() }}" wide>
                    <turbo-frame id="pick-frame" src="/lk/pokazy/vybor?user={{ $buyer->id }}" loading="lazy" class="block min-h-40">
                        <x-ui.skeleton :rows="3"/>
                    </turbo-frame>
                </x-ui.sheet>
                @if ($offers->isEmpty())
                    <x-ui.empty class="mt-4">Пока ничего не открыто.</x-ui.empty>
                @else
                    <div class="mt-4 flex flex-col gap-2">
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
            </section>

            @if ($interests->isNotEmpty())
                <section class="mt-8">
                    <h2 class="text-xl">Интерес</h2>
                    <div class="mt-4 flex flex-col gap-2">
                        @foreach ($interests as $interest)
                            @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => false])
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-ui.cabinet>
