{{-- Исполнители логиста (17.10.2026): «Исполнители» — люди строкой (город, пункт СДЭК — куда шлют документы, сколько вывозов
     в работе — кто свободен), нажатие —
     шторка правки и «Закрыть доступ»; админу — все, с логистом. «Ссылки» — первой строкой новая (одноразовая на неделю,
     подпись — кому), дальше общий `invites.row`. --}}
@php
    use App\Http\Cabinet\InviteController;
    $me = auth()->user();
    $base = InviteController::base();
    [$live, $past] = $invites->partition(fn ($i) => $i->isActive());
@endphp
<x-ui.shell title="Исполнители" :phone-heading="false">
    <x-ui.pills class="mb-4">
        @foreach ($pills as $key => $label)
            <x-ui.pill :href="'/contractors'.($key === 'people' ? '' : '?preset='.$key)" :current="$pill === $key" data-turbo-action="replace">{{ $label }}@if ($counts[$key]) <span class="nums">{{ $counts[$key] }}</span>@endif</x-ui.pill>
        @endforeach
    </x-ui.pills>

    @if ($pill === 'people')
        <div class="list">
            @if ($me->isLogist())<x-logistics.contractor-new label="Добавить исполнителя"/>@endif
                @foreach ($people as $user)
                    <div class="contents" data-controller="sheet">
                        <x-ui.sheet-row>
                            <x-ui.avatar :user="$user" :size="44"/>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $user->name }}</span>
                                <span class="row-sub">{{ implode(', ', array_filter([$user->logistics_city, $user->cdek_point ? 'СДЭК: '.$user->cdek_point : 'пункта СДЭК нет', $me->isAdmin() ? $user->logist?->shortName() : null])) }}</span>
                                @if ($user->busy)<span class="row-sub text-accent-text">в работе {{ $user->busy }}</span>@endif
                            </span>
                            <x-ui.chevron/>
                        </x-ui.sheet-row>
                        <x-ui.sheet id="contractor-{{ $user->id }}" :title="$user->name">
                            <form method="post" action="/contractors/{{ $user->id }}" class="flex flex-col gap-4">
                                @csrf @method('put')
                                <div class="fields">
                                    <x-ui.field name="logistics_city" id="contractor-city-{{ $user->id }}" label="Город" :value="$user->logistics_city"/>
                                    <x-ui.field name="cdek_point" id="contractor-cdek-{{ $user->id }}" label="Пункт СДЭК" type="textarea" :value="$user->cdek_point"/>
                                </div>
                                @if ($user->phone)<a href="tel:{{ $user->phone }}" class="text-accent-text nums">{{ \App\Support\Phone::format(\App\Support\Phone::normalize($user->phone) ?? $user->phone) }}</a>@endif
                                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                            </form>
                            <form method="post" action="/contractors/{{ $user->id }}/password" class="mt-2" data-turbo-confirm="Прежний пароль {{ $user->shortName() }} перестанет действовать">
                                @csrf<x-ui.button variant="quiet" block>Новый пароль</x-ui.button>
                            </form>
                            <form method="post" action="/contractors/{{ $user->id }}/revoke" class="mt-2" data-turbo-confirm="Закрыть {{ $user->shortName() }} доступ?">
                                @csrf<x-ui.button variant="ghost" block>Закрыть доступ</x-ui.button>
                            </form>
                        </x-ui.sheet>
                    </div>
                @endforeach
        </div>
        @if ($people->isEmpty() && ! $me->isLogist())<x-ui.empty class="mt-4">Исполнителей пока нет</x-ui.empty>@endif
    @else
        <div class="list">
            <div data-controller="sheet" class="contents">
                <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
                    <x-ui.row-icon name="plus" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium">Новая ссылка</span>
                </button>
                <x-ui.sheet id="invite-new" title="Новая ссылка">
                    <form method="post" action="/contractors/invites" class="flex flex-col gap-3">
                        @csrf
                        <div class="fields"><x-ui.field name="label" label="Кому" placeholder="Имя исполнителя"/></div>
                        <x-ui.sheet-foot>Создать ссылку</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
            @foreach ($live as $invite)
                @include('invites.row')
            @endforeach
        </div>
        @if ($past->isNotEmpty())
            <section class="mt-6">
                <h2 class="list-head">Сработали и выключенные</h2>
                <div class="list">
                    @foreach ($past as $invite)
                        @include('invites.row')
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</x-ui.shell>
