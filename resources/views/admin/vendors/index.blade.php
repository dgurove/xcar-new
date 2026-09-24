{{-- Вендоры списком: пилюли по типу и «Выключенные», первая строка — новый (как «Новая ссылка»). --}}
@php use App\Vendors\Kind; use App\Workflow\Track; $q = fn ($p) => '/settings/vendors'.($p ? '?'.$p : ''); @endphp
<x-ui.cabinet title="Вендоры">
    <div class="flex flex-wrap gap-1.5">
        <x-ui.pill :href="$q('')" :current="!$kind && !$off">Все</x-ui.pill>
        @foreach (Kind::cases() as $k)
            @if ($counts[$k->value] ?? 0)<x-ui.pill :href="$q('kind='.$k->value)" :current="$kind === $k">{{ $k->plural() }} <span class="nums text-ink-dim">{{ $counts[$k->value] }}</span></x-ui.pill>@endif
        @endforeach
        @if ($offCount)<x-ui.pill :href="$q('off=1')" :current="$off">Выключенные <span class="nums text-ink-dim">{{ $offCount }}</span></x-ui.pill>@endif
    </div>
    <div class="list">
        <div data-controller="sheet">
            <button type="button" class="row w-full text-left" data-action="sheet#open">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-4"/></span>
                <span class="min-w-0 flex-1 text-accent-text">Новый вендор</span>
            </button>
            <x-ui.sheet id="vendor-new" title="Новый вендор" :open="$errors->has('name')">
                <form method="post" action="/settings/vendors" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="name" label="Название" required autofocus/>
                    <x-ui.field name="kind" label="Тип" :options="Kind::options()" value="insurer"/>
                    <x-ui.button block>Создать</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
        @foreach ($vendors as $vendor)
            <a href="/settings/vendors/{{ $vendor->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="truncate">{{ $vendor->name }}</div>
                    {{-- Маршруты словами: включённый — лаймовым, выключенный — тусклым. --}}
                    <div class="row-sub">
                        @unless ($vendor->is_active)<span class="text-ink">выключен</span>@endunless
                        @if ($vendor->agreementExpired())<span class="text-danger">договор истёк</span>@endif
                        @if ($vendor->kind !== Kind::Insurer)<span>{{ $vendor->kind->label() }}</span>@endif
                        @foreach (Track::cases() as $track)
                            @php $w = $vendor->workflow($track); @endphp
                            @if ($w)<span class="{{ $w->is_active ? 'text-accent-text' : '' }}">{{ mb_strtolower($track->label()) }} {{ $w->is_active ? ($track === Track::Service && !$w->auto_start ? 'по кнопке' : 'включён') : 'выключен' }}</span>@endif
                        @endforeach
                    </div>
                </div>
                <span class="flex shrink-0 flex-col items-end text-sm text-ink-muted tabular-nums">
                    @if ($vendor->offers_count)<span>{{ $vendor->offers_count }} предл.</span>@endif
                    @if ($vendor->stored_count)<span>{{ $vendor->stored_count }} на парковке</span>@endif
                </span>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
