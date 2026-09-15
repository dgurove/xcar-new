{{-- Тело шторки «Показать…»: сверху сам оффер (или сколько их), ниже один список строк с галкой —
     группы, затем покупатели. Одному предложению — текущее состояние (sync), пачке — только
     добавление. Грузится фреймом, чтобы галки были свежими. --}}
@php $count = $offers->count(); $rows = $groups->count() + $buyers->count(); @endphp
<turbo-frame id="show-frame">
    <form method="post" action="/lk/pokazy" class="flex flex-col gap-4" data-controller="select" data-turbo-frame="_top">
        @csrf
        @foreach ($offers as $o)<input type="hidden" name="offers[]" value="{{ $o->id }}">@endforeach
        @if ($single)<input type="hidden" name="sync" value="1">@endif
        <input type="hidden" name="back" value="{{ $back }}">

        @if ($single)
            @php $price = \App\Offers\PriceView::for($single, auth()->user()); @endphp
            <div class="row">
                <span class="row-photo"><x-offer.photo :media="$single->mainPhoto()" sizes="72px"/></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ $single->titleWithYear() }}</span>
                    <span class="row-sub"><span class="tag nums">№ {{ $single->number }}</span></span>
                </span>
                @if ($price->shown())<span class="nums shrink-0 text-sm">{{ $price::money($price->to) }} ₽</span>@endif
            </div>
        @else
            <div class="row">
                <span class="flex -space-x-3">
                    @foreach ($offers->take(3) as $o)<span class="row-photo !w-12 !h-9 ring-2 ring-surface"><x-offer.photo :media="$o->mainPhoto()" sizes="48px"/></span>@endforeach
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $count }} {{ \App\Support\Plural::of($count, ['автомобиль', 'автомобиля', 'автомобилей']) }}</span>
                    <span class="row-sub"><span class="truncate">{{ $offers->take(3)->map->title()->join(', ') }}{{ $count > 3 ? '…' : '' }}</span></span>
                </span>
            </div>
        @endif

        @if ($rows === 0)
            <x-ui.empty class="!py-12" href="/lk/pokupateli/priglasheniya" link="Пригласить покупателей">Показывать пока некому.</x-ui.empty>
        @else
            @if ($rows > 8)<input type="search" class="field-input field-s" placeholder="Найти" autocomplete="off" data-action="input->select#filter">@endif
            <div class="flex flex-col gap-2">
                @foreach ($groups as $g)
                    <label class="row row-check" data-select-target="item" data-name="{{ mb_strtolower($g->name) }}">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-surface-3"><x-ui.icon name="users" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $g->name }}</span>
                            <span class="row-sub"><span class="nums">{{ $g->members_count }} {{ \App\Support\Plural::of($g->members_count, ['человек', 'человека', 'человек']) }}</span></span>
                        </span>
                        <span class="check"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked(in_array($g->id, $checkedGroups, true)) data-select-target="box" data-action="select#count"></span>
                    </label>
                @endforeach
                @foreach ($buyers as $b)
                    <label class="row row-check" data-select-target="item" data-name="{{ mb_strtolower($b->name.' '.$b->login) }}">
                        <x-ui.avatar :user="$b" :size="40"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $b->name }}</span>
                            @if ($b->login)<span class="row-sub"><span class="tag nums">{{ $b->login }}</span></span>@endif
                        </span>
                        <span class="check"><input type="checkbox" name="users[]" value="{{ $b->id }}" @checked(in_array($b->id, $checkedUsers, true)) data-select-target="box" data-action="select#count"></span>
                    </label>
                @endforeach
            </div>
            <div class="sticky bottom-0 -mx-1 bg-surface px-1 pb-1 pt-3">
                <button type="submit" class="btn btn-accent w-full" @unless ($single) data-select-target="submit" disabled @endunless>{{ $single ? 'Сохранить' : 'Показать' }} <span class="nums" data-select-target="count"></span></button>
            </div>
        @endif
    </form>
</turbo-frame>
