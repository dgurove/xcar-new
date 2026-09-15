{{-- Тело шторки «Показать…»: группы и покупатели чипами; одному предложению — текущее состояние (sync),
     пачке — только добавление. Грузится фреймом, чтобы галки были свежими. --}}
@php $count = $offers->count(); @endphp
<turbo-frame id="show-frame">
    <form method="post" action="/lk/pokazy" class="flex flex-col gap-5" data-controller="select" data-turbo-frame="_top">
        @csrf
        @foreach ($offers as $o)<input type="hidden" name="offers[]" value="{{ $o->id }}">@endforeach
        @if ($single)<input type="hidden" name="sync" value="1">@endif
        <input type="hidden" name="back" value="{{ $back }}">
        <div class="text-sm text-ink-muted">
            @if ($single)
                <span class="tag nums">№ {{ $single->number }}</span> {{ $single->titleWithYear() }}
            @else
                {{ $count }} {{ \App\Support\Plural::of($count, ['автомобиль', 'автомобиля', 'автомобилей']) }}: {{ $offers->take(3)->map->title()->join(', ') }}{{ $count > 3 ? '…' : '' }}
            @endif
        </div>

        @if ($groups->isEmpty() && $buyers->isEmpty())
            <x-ui.empty href="/lk/pokupateli/priglasheniya" link="Пригласить покупателей">Показывать пока некому.</x-ui.empty>
        @else
            @if ($groups->isNotEmpty())
                <div>
                    <div class="mb-2 text-sm text-ink-dim">Группы</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($groups as $g)
                            <label class="choice"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked(in_array($g->id, $checkedGroups, true)) data-select-target="box" data-action="select#count"><span class="gap-1.5"><x-ui.icon name="users" class="size-4"/>{{ $g->name }} <span class="nums opacity-60">{{ $g->members_count }}</span></span></label>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($buyers->isNotEmpty())
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm text-ink-dim">
                        <span>Покупатели</span>
                        @if ($buyers->count() > 12)<input type="search" class="field-input field-s !min-h-9 !w-40 text-sm" placeholder="Найти" data-action="input->select#filter">@endif
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($buyers as $b)
                            <label class="choice" data-select-target="item" data-name="{{ mb_strtolower($b->name.' '.$b->login) }}"><input type="checkbox" name="users[]" value="{{ $b->id }}" @checked(in_array($b->id, $checkedUsers, true)) data-select-target="box" data-action="select#count"><span class="gap-1.5"><x-ui.avatar :user="$b" :size="20"/>{{ $b->shortName() }}</span></label>
                        @endforeach
                    </div>
                </div>
            @endif
            <button type="submit" class="btn btn-accent w-full">{{ $single ? 'Сохранить' : 'Показать' }} <span class="nums" data-select-target="count"></span></button>
        @endif
    </form>
</turbo-frame>
