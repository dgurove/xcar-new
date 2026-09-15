{{-- Покупатель: кто он, в каких группах, что видит и чем интересовался. Прямой показ снимается крестиком на карточке. --}}
@php $me = auth()->user(); $link = ($link['user'] ?? null) === $buyer->id ? $link : null; @endphp
<x-ui.cabinet :title="$buyer->name" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Покупатели', '/lk/pokupateli'], [$buyer->name]]">
    <div class="box flex flex-wrap items-center gap-4" data-controller="sheet">
        <x-ui.avatar :user="$buyer" :size="56" class="text-xl"/>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-1.5">
                @if ($buyer->login)<span class="tag nums">{{ $buyer->login }}</span>@endif
                @if ($buyer->phone)<a href="tel:+{{ $buyer->phone }}" class="tag nums">{{ $buyer->phoneFormatted() }}</a>@endif
                @if ($buyer->email)<a href="mailto:{{ $buyer->email }}" class="tag">{{ $buyer->email }}</a>@endif
                <span class="tag nums">с {{ $buyer->created_at->translatedFormat('j M') }}</span>
            </div>
        </div>
        <form method="post" action="/lk/pokupateli/{{ $buyer->id }}/parol"
            data-turbo-confirm="Выдать ссылку для нового пароля?" data-turbo-confirm-label="Выдать"
            data-turbo-confirm-text="{{ $buyer->shortName() }} откроет её и придумает новый пароль. Действует сутки, один раз.">
            @csrf
            <button type="submit" class="btn btn-s btn-quiet"><x-ui.icon name="key" class="size-5"/> Новый пароль</button>
        </form>
        <x-ui.sheet id="password-link" title="Ссылка для нового пароля" :open="$link !== null">
            @if ($link)
                <x-ui.copy-link :url="$link['url']" title="Новый пароль на xcar">
                    <p class="text-sm text-ink-muted">Отправьте её {{ $buyer->shortName() }}. Логин для входа — <span class="nums">{{ $buyer->loginLabel() }}</span>.</p>
                </x-ui.copy-link>
            @endif
        </x-ui.sheet>
    </div>

    @if ($groups->isNotEmpty())
        <section class="box mt-4">
            <h2 class="text-lg">Группы</h2>
            <form method="post" action="/lk/pokupateli/{{ $buyer->id }}/gruppy" class="mt-3 flex flex-wrap gap-1.5" data-controller="autosubmit">
                @csrf @method('put')
                <input type="hidden" name="groups[]" value="" disabled>
                @foreach ($groups as $g)
                    <label class="choice"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked($buyer->groups->contains('id', $g->id)) data-action="change->autosubmit#submit"><span>{{ $g->name }}</span></label>
                @endforeach
            </form>
        </section>
    @endif

    <section class="mt-6" data-controller="sheet">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl">Видит <span class="nums text-ink-dim">{{ $offers->count() }}</span></h2>
            <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Открыть предложения</button>
        </div>
        <x-ui.sheet id="pick" title="Открыть {{ $buyer->shortName() }}" wide>
            <turbo-frame id="pick-frame" src="/lk/pokazy/vybor?user={{ $buyer->id }}" loading="lazy" class="block min-h-40">
                <x-ui.skeleton :rows="3"/>
            </turbo-frame>
        </x-ui.sheet>
        @if ($offers->isEmpty())
            <x-ui.empty class="mt-4">Пока ничего не открыто — выберите предложения кнопкой выше или добавьте в группу.</x-ui.empty>
        @else
            <div class="cards cards--list mt-4">
                @foreach ($offers as $offer)
                    <div class="relative">
                        <x-offer.card :offer="$offer"/>
                        <div class="absolute right-3 top-3 flex items-center gap-1.5">
                            @foreach ($via[$offer->id] ?? [] as $s)<span class="tag bg-surface">{{ $s->group->name }}</span>@endforeach
                            @if (in_array($offer->id, $direct, true))
                                <form method="post" action="/lk/pokazy" data-turbo-confirm="Закрыть {{ $offer->titleWithYear() }} для {{ $buyer->shortName() }}?" data-turbo-confirm-label="Закрыть">
                                    @csrf @method('delete')
                                    <input type="hidden" name="offer" value="{{ $offer->id }}"><input type="hidden" name="user" value="{{ $buyer->id }}">
                                    <button type="submit" class="btn btn-s btn-glass btn-round" aria-label="Закрыть предложение"><x-ui.icon name="x" class="size-4"/></button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if ($interests->isNotEmpty())
        <section class="mt-6">
            <h2 class="text-xl">Интерес</h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($interests as $interest)
                    @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => false])
                @endforeach
            </div>
        </section>
    @endif
</x-ui.cabinet>
