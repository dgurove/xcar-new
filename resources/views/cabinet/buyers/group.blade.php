{{-- Группа: шапка-контакт с кружком, состав списком с галками (автосохранение), что видит группа.
     «Изменить» в ряду действий — переименовать или удалить; «Открыть предложения» — в полосе внизу. --}}
@php $n = $group->members->count(); @endphp
<x-ui.cabinet :title="$group->name">

    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="lg:col-start-2 lg:row-start-1">
            <x-ui.contact :name="$group->name" sidebar>
                <x-slot:chips>
                    <span class="tag nums">{{ $n }} {{ \App\Support\Plural::of($n, ['человек', 'человека', 'человек']) }}</span>
                </x-slot:chips>
                <x-slot:acts>
                    <div data-controller="sheet" class="contents">
                        <button type="button" class="act" data-action="sheet#open"><span class="btn btn-quiet btn-round"><x-ui.icon name="edit"/></span>Изменить</button>
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
                </x-slot:acts>
            </x-ui.contact>

            <section class="mt-8">
                <h2 class="text-xl">Состав</h2>
                @if ($buyers->isEmpty())
                    <x-ui.empty class="mt-4" href="/lk/priglasheniya" link="Пригласить">Покупателей пока нет.</x-ui.empty>
                @else
                    <form method="post" action="/lk/pokupateli/gruppy/{{ $group->id }}/sostav" class="mt-4 flex flex-col gap-2" data-controller="autosubmit select">
                        @csrf @method('put')
                        @if ($buyers->count() > 8)<input type="search" class="field-input field-s mb-1" placeholder="Найти" autocomplete="off" data-action="input->select#filter">@endif
                        @foreach ($buyers as $b)
                            <label class="row row-check" data-select-target="item" data-name="{{ mb_strtolower($b->name.' '.$b->login) }}">
                                <x-ui.avatar :user="$b" :size="40"/>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $b->name }}</span>
                                    @if ($b->login)<span class="row-sub"><span class="tag nums">{{ $b->login }}</span></span>@endif
                                </span>
                                <span class="check"><input type="checkbox" name="users[]" value="{{ $b->id }}" @checked($group->members->contains('id', $b->id)) data-action="change->autosubmit#submit"></span>
                            </label>
                        @endforeach
                    </form>
                @endif
            </section>
        </div>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            <section data-controller="sheet">
                <h2 class="text-xl">Видит @if ($offers->isNotEmpty())<span class="nums text-ink-dim">{{ $offers->count() }}</span>@endif</h2>
                <x-ui.sheet id="pick" title="Открыть группе «{{ $group->name }}»" wide>
                    <turbo-frame id="pick-frame" src="/lk/pokazy/vybor?group={{ $group->id }}" loading="lazy" class="block min-h-40">
                        <x-ui.skeleton :rows="3"/>
                    </turbo-frame>
                </x-ui.sheet>
                @if ($offers->isEmpty())
                    <x-ui.empty class="mt-4">Группе пока ничего не открыто.</x-ui.empty>
                @else
                    <div class="mt-4 flex flex-col gap-2">
                        @foreach ($offers as $offer)
                            @include('cabinet.buyers.offer-row', ['offer' => $offer, 'hide' => ['group' => $group->id], 'hideText' => 'Группа «'.$group->name.'» больше не увидит это предложение.'])
                        @endforeach
                    </div>
                @endif
                <x-ui.action-bar>
                    <button type="button" class="btn btn-accent min-w-0 flex-1 md:flex-none" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Открыть предложения</button>
                </x-ui.action-bar>
            </section>
        </div>
    </div>
</x-ui.cabinet>
