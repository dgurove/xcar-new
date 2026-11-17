{{-- Вендоры списком: пилюли по типу и «Выключенные», первая строка — новый (как «Новая ссылка»). --}}
@php use App\Vendors\Kind; use App\Workflow\Track; $q = fn ($p) => '/settings/vendors'.($p ? '?'.$p : ''); @endphp
<x-ui.cabinet title="Вендоры">
    {{-- Тот же тулбар, что у «Пользователей» (17.11.2026, владелец: шапки разделов «скачут»): пилюли, под ними сортировка. «Выключенные» —
         пилюлей `kind=off`. --}}
    @php
        $pills = ['' => 'Все'] + collect(Kind::cases())->filter(fn ($k) => $counts[$k->value] ?? 0)->mapWithKeys(fn ($k) => [$k->value => $k->plural()])->all() + ($offCount ? ['off' => 'Выключенные'] : []);
        $pillCounts = collect(Kind::cases())->mapWithKeys(fn ($k) => [$k->value => $counts[$k->value] ?? 0])->all() + ['off' => $offCount];
    @endphp
    <x-ui.toolbar :sort="$sort" :pills="$pills" :pill="$off ? 'off' : ($kind?->value ?? '')" pill-param="kind" pill-home="" :counts="$pillCounts" name="vendors"/>
    <div class="list">
        <div data-controller="sheet">
            <x-ui.sheet-row>
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-4"/></span>
                <span class="min-w-0 flex-1 text-accent-text">Новый вендор</span>
            </x-ui.sheet-row>
            <x-ui.sheet id="vendor-new" title="Новый вендор">
                <form method="post" action="/settings/vendors" class="flex flex-col gap-4">
                    @csrf
                    <div class="fields">
                        <x-ui.field name="name" label="Название" required/>
                        <x-ui.field name="kind" label="Тип" :options="Kind::options()" value="insurer"/>
                    </div>
                    <x-ui.sheet-foot>Создать</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
        @foreach ($vendors as $vendor)
            <a href="/settings/vendors/{{ $vendor->id }}" class="row">
                <x-vendor.logo :vendor="$vendor" class="size-9"/>
                <div class="min-w-0 flex-1">
                    <div class="truncate">{{ $vendor->name }}</div>
                    {{-- Маршруты словами: включённый — лаймовым, выключенный — тусклым. --}}
                    <div class="row-sub">
                        @unless ($vendor->is_active)<span class="text-ink">выключен</span>@endunless
                        @if ($vendor->kind !== Kind::Insurer)<span>{{ $vendor->kind->label() }}</span>@endif
                        @foreach (Track::cases() as $track)
                            @php $w = $vendor->workflow($track); @endphp
                            @if ($w)<span class="{{ $w->is_active ? 'text-accent-text' : '' }}">{{ mb_strtolower($track->label()) }} {{ $w->is_active ? 'включена' : 'выключена' }}</span>@endif
                        @endforeach
                    </div>
                </div>
                <span class="flex shrink-0 flex-col items-end text-sm text-ink-muted">
                    @if ($vendor->offers_count)<span>{{ $vendor->offers_count }} предл.</span>@endif
                </span>
                <x-ui.chevron/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
