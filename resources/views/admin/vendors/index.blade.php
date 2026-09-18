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
    <div class="flex flex-col gap-2" data-controller="sheet">
        <button type="button" class="row w-full text-left" data-action="sheet#open">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Новый вендор</span>
        </button>
        <x-ui.sheet id="vendor-new" title="Новый вендор" :open="$errors->has('name')">
            <form method="post" action="/settings/vendors" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="name" label="Название" required autofocus/>
                <x-ui.field name="kind" label="Тип" :options="Kind::options()" value="insurer"/>
                <x-ui.button block>Создать</x-ui.button>
            </form>
        </x-ui.sheet>
        @foreach ($vendors as $vendor)
            <a href="/settings/vendors/{{ $vendor->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="font-medium">{{ $vendor->name }}</span>@unless ($vendor->is_active)<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">выключен</x-ui.pill>@endunless</div>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @if ($vendor->kind !== Kind::Insurer)<span class="tag">{{ $vendor->kind->label() }}</span>@endif
                        @foreach (Track::cases() as $track)
                            @php $w = $vendor->workflow($track); @endphp
                            @if ($w)<span class="tag" @if ($w->is_active) style="--tag-bg:#f0f7d8;--tag-text:#669709;--tag-bg-d:#1a2605;--tag-text-d:#a6cf3a" @endif>{{ $track->label() }}: {{ $w->is_active ? 'включён'.($track === Track::Service && !$w->auto_start ? ', по кнопке' : '') : 'выключен' }}</span>@endif
                        @endforeach
                        @if ($vendor->agreementExpired())<span class="tag text-danger">договор истёк</span>@endif
                    </div>
                </div>
                <span class="flex shrink-0 flex-col items-end text-sm text-ink-muted tabular-nums">
                    @if ($vendor->offers_count)<span>{{ $vendor->offers_count }}</span>@endif
                    @if ($vendor->stored_count)<span>{{ $vendor->stored_count }} на стоянке</span>@endif
                </span>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
