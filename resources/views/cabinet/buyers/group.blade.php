{{-- Группа: состав чипами с автосохранением, что видит группа, «+ Открыть предложения». --}}
<x-ui.cabinet :title="$group->name" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Покупатели', '/lk/pokupateli'], [$group->name]]">
    <x-slot:actions>
        <div data-controller="sheet" class="contents">
            <button type="button" class="btn btn-s btn-quiet btn-round" data-action="sheet#open" aria-label="Переименовать или удалить"><x-ui.icon name="more" class="size-5"/></button>
            <x-ui.sheet id="group-edit" :title="$group->name">
                <form method="post" action="/lk/pokupateli/gruppy/{{ $group->id }}" class="flex flex-col gap-4">
                    @csrf @method('put')
                    <x-ui.field name="name" label="Название" :value="$group->name" required maxlength="60"/>
                    <x-ui.button block>Сохранить</x-ui.button>
                </form>
                <form method="post" action="/lk/pokupateli/gruppy/{{ $group->id }}" class="mt-2"
                    data-turbo-confirm="Удалить группу «{{ $group->name }}»?" data-turbo-confirm-label="Удалить"
                    data-turbo-confirm-text="Покупатели останутся у вас, но то, что было открыто им через группу, закроется.">
                    @csrf @method('delete')
                    <x-ui.button block variant="danger">Удалить группу</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    </x-slot:actions>

    <section class="box">
        <h2 class="text-lg">Состав <span class="nums text-ink-dim">{{ $group->members->count() }}</span></h2>
        @if ($buyers->isEmpty())
            <p class="mt-3 text-ink-muted">Покупателей пока нет — <a href="/lk/pokupateli/priglasheniya" class="text-accent-text hover:underline">пригласите</a>.</p>
        @else
            <form method="post" action="/lk/pokupateli/gruppy/{{ $group->id }}/sostav" class="mt-3 flex flex-wrap gap-1.5" data-controller="autosubmit">
                @csrf @method('put')
                @foreach ($buyers as $b)
                    <label class="choice"><input type="checkbox" name="users[]" value="{{ $b->id }}" @checked($group->members->contains('id', $b->id)) data-action="change->autosubmit#submit"><span class="gap-1.5"><x-ui.avatar :user="$b" :size="20"/>{{ $b->shortName() }}</span></label>
                @endforeach
            </form>
        @endif
    </section>

    <section class="mt-6" data-controller="sheet">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl">Видит <span class="nums text-ink-dim">{{ $offers->count() }}</span></h2>
            <button type="button" class="btn btn-s btn-accent rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Открыть предложения</button>
        </div>
        <x-ui.sheet id="pick" title="Открыть группе «{{ $group->name }}»" wide>
            <turbo-frame id="pick-frame" src="/lk/pokazy/vybor?group={{ $group->id }}" loading="lazy" class="block min-h-40">
                <x-ui.skeleton :rows="3"/>
            </turbo-frame>
        </x-ui.sheet>
        @if ($offers->isEmpty())
            <x-ui.empty class="mt-4">Группе пока ничего не открыто.</x-ui.empty>
        @else
            <div class="cards cards--list mt-4">
                @foreach ($offers as $offer)
                    <div class="relative">
                        <x-offer.card :offer="$offer"/>
                        <form method="post" action="/lk/pokazy" class="absolute right-3 top-3" data-turbo-confirm="Закрыть {{ $offer->titleWithYear() }} для группы?" data-turbo-confirm-label="Закрыть">
                            @csrf @method('delete')
                            <input type="hidden" name="offer" value="{{ $offer->id }}"><input type="hidden" name="group" value="{{ $group->id }}">
                            <button type="submit" class="btn btn-s btn-glass btn-round" aria-label="Закрыть предложение"><x-ui.icon name="x" class="size-4"/></button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</x-ui.cabinet>
