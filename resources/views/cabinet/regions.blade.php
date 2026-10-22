{{-- «Регионы» (22.10.2026, Сахиб с Урала: «слишком много машин, которые мне не нужны»): откуда показывать предложения.
     Сверху «Вся Россия», ниже федеральные округа строкой с галкой «весь округ» и их регионы галками; поиск сужает список.
     Машина не из отмеченных — его в волнах показа нет (`SyncViewers`), у его покупателей тоже. Себе — в кабинете сайта,
     админ за человека — из его карточки в CRM. --}}
@php
    use App\Cars\Region;
    $chosen = $chosen ? array_map('intval', $chosen) : null;
    $on = fn ($id) => $chosen === null || in_array($id, $chosen, true);
    $groups = collect(Region::DISTRICTS)->map(fn ($plates) => $regions->filter(fn ($r) => in_array(str_pad((string) $r->plate, 2, '0', STR_PAD_LEFT), $plates, true))->values());
@endphp
<x-ui.cabinet title="Регионы" :back="$back">
    <form method="post" action="{{ $action }}" class="flex flex-col gap-3" data-controller="regions">
        @csrf @method('put')
        @unless ($person->is(auth()->user()))
            <p class="text-ink-muted">{{ $person->name }}</p>
        @endunless
        <div class="list">
            <label class="row row-check items-center gap-3">
                <x-ui.row-icon name="map-pin"/>
                <span class="min-w-0 flex-1 font-medium">Вся Россия</span>
                <span class="check"><input type="checkbox" data-regions-target="all" data-action="regions#all" @checked($chosen === null)></span>
            </label>
        </div>
        <div class="toolbar-search search-field">
            <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
            <input type="search" placeholder="Регион" enterkeyhint="search" autocomplete="off" aria-label="Поиск региона" data-action="input->regions#search">
        </div>
        @foreach ($groups as $district => $list)
            <section data-regions-target="group">
                <label class="list-head row-check flex items-center gap-3">
                    <span class="min-w-0 flex-1">{{ $district }}</span>
                    <span class="check"><input type="checkbox" data-regions-target="district" data-action="regions#district"></span>
                </label>
                <div class="list">
                    @foreach ($list as $region)
                        <label class="row row-check items-center gap-3" data-regions-target="row" data-name="{{ mb_strtolower($region->name) }}">
                            <span class="min-w-0 flex-1">{{ $region->name }}</span>
                            <span class="check"><input type="checkbox" name="regions[]" value="{{ $region->id }}" data-action="regions#sync" @checked($on($region->id))></span>
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach
        @error('regions')<p class="text-danger">{{ $message }}</p>@enderror
        <button type="submit" class="btn btn-accent w-full">Сохранить</button>
    </form>
</x-ui.cabinet>
